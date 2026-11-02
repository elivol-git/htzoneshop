# Deploying to AWS (EC2 + RDS + Secrets Manager)

These are manual console steps, roughly 30 minutes. The examples use region `eu-central-1`; any region works if you use it everywhere.

```
Internet ──80──▶ EC2 (Docker: nginx + php-fpm, Laravel)
                  │  IAM role ──▶ Secrets Manager  htzone/prod
                  └──3306──▶ RDS MySQL 8.4 (private)
                  └──HTTPS──▶ Hugging Face Inference API
```

## 1. Security groups (VPC → Security groups)

| Name | Inbound |
|------|---------|
| `htzone-web` | TCP 80 from `0.0.0.0/0`; TCP 22 from *your IP* only |
| `htzone-db`  | TCP 3306 from source `htzone-web` (the security group, not a CIDR) |

## 2. RDS MySQL

RDS → Create database:
- Engine **MySQL 8.4**, template *Free tier* / *Dev*, instance `db.t4g.micro`, 20 GB gp3.
- DB identifier `htzone`, master username `htzone`, then **self-managed** password (save it for step 3).
- Connectivity: same VPC as the EC2, **Public access: No**, security group `htzone-db`.
- Additional configuration → Initial database name `htzone`.
- The default parameter group is fine: `ngram_token_size` defaults to 2, and the app disables FULLTEXT stopwords per session.

Copy the **endpoint** when the DB is ready.

## 3. Secrets Manager

Store a new secret → *Other type of secret* → Plaintext JSON:

```json
{
  "APP_KEY": "base64:<generate: openssl rand -base64 32>",
  "APP_URL": "http://<elastic-ip>",
  "DB_HOST": "<rds-endpoint>",
  "DB_DATABASE": "htzone",
  "DB_USERNAME": "htzone",
  "DB_PASSWORD": "<rds-password>",
  "HF_TOKEN": "hf_..."
}
```

Name it `htzone/prod`. The container reads this secret at startup through the instance role, so no credentials are kept on disk in the repo or the image.

## 4. IAM role for EC2

IAM → Roles → Create → AWS service **EC2** → name it `htzone-ec2`, then add this inline policy:

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Action": "secretsmanager:GetSecretValue",
    "Resource": "arn:aws:secretsmanager:eu-central-1:<account-id>:secret:htzone/prod-*"
  }]
}
```

## 5. EC2

Launch instance:
- **Amazon Linux 2023**, `t3.small` (2 GB RAM, which is enough for the Docker build), 16 GB gp3.
- Security group `htzone-web`, IAM instance profile `htzone-ec2`, your key pair.
- Allocate an **Elastic IP** and associate it with the instance.

SSH in and install Docker:

```bash
sudo dnf install -y docker git
sudo systemctl enable --now docker
sudo usermod -aG docker ec2-user
sudo mkdir -p /usr/local/lib/docker/cli-plugins
sudo curl -SL https://github.com/docker/compose/releases/latest/download/docker-compose-linux-x86_64 \
  -o /usr/local/lib/docker/cli-plugins/docker-compose
sudo chmod +x /usr/local/lib/docker/cli-plugins/docker-compose
# re-login so the docker group applies
```

## 6. Deploy

```bash
git clone https://github.com/<you>/htzoneshop.git && cd htzoneshop
cat > .env <<'ENV'
AWS_SECRET_ID=htzone/prod
AWS_REGION=eu-central-1
ENV
./deploy.sh
```

On first boot the container:
1. fetches the secret and writes `.env` inside the container (`docker/fetch-secrets.php`),
2. runs migrations,
3. imports `catalogue/catalog_no_prices.csv`, an idempotent upsert.

Then `deploy.sh` runs `catalog:embed`, which embeds the 1000 products via Hugging Face. That takes about 1–2 minutes and runs only once, because later runs skip products that already have a vector.

Check that it works with `curl http://<elastic-ip>/api/search?q=אייפון`.

To update, run `git pull` and then `./deploy.sh` again.

## Optional: HTTPS

Point a domain (or `<ip>.sslip.io`) at the Elastic IP and put Caddy in front of the app. Caddy obtains a Let's Encrypt certificate automatically. This step is not done in this submission.

## Troubleshooting

- `docker compose -f compose.prod.yml logs -f app` shows the app logs.
- `database unreachable`: check that the `htzone-db` security group allows 3306 from `htzone-web` and that `DB_HOST` is correct.
- `AccessDeniedException` from Secrets Manager: the instance role is missing or its ARN or region doesn't match the secret.
- A yellow "keyword only" notice in the UI means Hugging Face is unreachable or `HF_TOKEN` is missing or invalid.
