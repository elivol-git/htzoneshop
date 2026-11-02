<?php

return [
    'max_results' => 24,

    // Reciprocal Rank Fusion constant and per-retriever weights.
    'rrf_k' => 60,
    'keyword_weight' => 1.0,
    'semantic_weight' => 1.0,

    // Semantic-only hits must be at least this similar (cosine) to the query,
    // and within `semantic_relative_window` of the best hit. Keeps "no results"
    // meaningful for gibberish queries. e5 cosine scores live in ~0.7–0.9.
    'semantic_min_score' => (float) env('SEARCH_SEMANTIC_MIN', 0.80),
    'semantic_relative_window' => (float) env('SEARCH_SEMANTIC_WINDOW', 0.05),
    'semantic_candidates' => 60,

    'keyword_candidates' => 100,

    // Hebrew/English function words ignored by keyword matching
    // (embeddings still see the full query).
    'stopwords' => [
        'של', 'עם', 'על', 'את', 'זה', 'זו', 'מה', 'מי', 'או', 'גם', 'כל', 'לא', 'כן', 'יש', 'אין', 'הוא', 'היא',
        'משהו', 'משהי', 'למישהו', 'למישהי', 'מישהו', 'מישהי', 'שיעזור', 'שתעזור', 'יעזור', 'לעזור', 'עוזר',
        'שאוהב', 'שאוהבת', 'אוהב', 'אוהבת', 'רוצה', 'צריך', 'צריכה', 'מחפש', 'מחפשת', 'טוב', 'טובה', 'הכי',
        'בשביל', 'עבור', 'לי', 'שלי', 'אני', 'מן', 'מ', 'ל', 'ב', 'ה', 'ו', 'ש',
        'a', 'an', 'the', 'for', 'of', 'to', 'and', 'or', 'with', 'my', 'some', 'something', 'someone', 'who', 'that',
        'loves', 'love', 'likes', 'need', 'want', 'best', 'good', 'help', 'helps', 'from', 'in', 'on',
    ],

    /*
    | Synonym groups (each group = interchangeable terms, Hebrew/English/slang).
    | Matched on normalized tokens/phrases; every member is added to the keyword
    | query. Covers transliterations ("אייפון" -> iphone) that embeddings miss.
    */
    'synonyms' => [
        ['iphone', 'אייפון', 'איפון', 'apple סמארטפון', 'smartphone apple'],
        ['samsung', 'סמסונג', 'גלקסי', 'galaxy'],
        ['xiaomi', 'שיאומי'],
        ['dyson', 'דייסון'],
        ['apple', 'אפל'],
        ['ipad', 'אייפד'],
        ['macbook', 'מקבוק'],
        ['airpods', 'איירפודס', 'אוזניות apple'],
        ['playstation', 'פלייסטיישן', 'ps5'],
        ['xbox', 'אקסבוקס'],
        ['nintendo', 'נינטנדו'],
        ['smartphone', 'phone', 'סמארטפון', 'טלפון', 'סלולרי', 'פלאפון', 'נייד'],
        ['vacuum', 'שואב אבק', 'שואב', 'שואבי אבק'],
        ['robot vacuum', 'רובוט שואב', 'רובוט'],
        ['laptop', 'מחשב נייד', 'לפטופ', 'notebook'],
        ['headphones', 'earbuds', 'אוזניות'],
        ['tv', 'television', 'טלוויזיה', 'טלויזיה'],
        ['coffee', 'קפה'],
        ['espresso', 'אספרסו'],
        ['air fryer', 'airfryer', 'סיר טיגון', 'מטגן אוויר', 'מטהג אוויר', 'נינג\'ה'],
        ['blender', 'בלנדר'],
        ['mixer', 'מיקסר'],
        ['knife', 'סכין', 'סכינים'],
        ['pan', 'מחבת', 'מחבתות'],
        ['pot', 'סיר', 'סירים'],
        ['cooking', 'בישול', 'לבשל', 'מבשל', 'מבשלת', 'מטבח', 'kitchen', 'cook'],
        ['baking', 'אפייה', 'לאפות', 'אופה'],
        ['gift', 'מתנה', 'מארז', 'מתנות'],
        ['dog', 'כלב', 'כלבים'],
        ['cat', 'חתול', 'חתולים'],
        ['pet', 'חיית מחמד', 'חיות מחמד'],
        ['hair', 'שיער', 'שערות', 'שער'],
        ['pet hair', 'שיער חיות', 'שערות כלב', 'פרווה'],
        ['sofa', 'ספה', 'ספות', 'couch'],
        ['clean', 'cleaning', 'לנקות', 'ניקוי', 'ניקיון', 'מנקה'],
        ['lint roller', 'רולר', 'מסיר מוך'],
        ['watch', 'שעון', 'smartwatch', 'שעון חכם'],
        ['speaker', 'רמקול'],
        ['camera', 'מצלמה'],
        ['drone', 'רחפן'],
        ['printer', 'מדפסת'],
        ['monitor', 'מסך'],
        ['keyboard', 'מקלדת'],
        ['mouse', 'עכבר'],
        ['router', 'ראוטר', 'נתב'],
        ['bike', 'bicycle', 'אופניים'],
        ['scooter', 'קורקינט', 'סקוטר'],
        ['tent', 'אוהל'],
        ['suitcase', 'מזוודה'],
        ['wine', 'יין'],
        ['tea', 'תה'],
        ['baby', 'תינוק', 'תינוקות'],
        ['kids', 'ילדים', 'ילד'],
        ['toy', 'צעצוע', 'צעצועים'],
        ['fan', 'מאוורר'],
        ['air conditioner', 'מזגן'],
        ['refrigerator', 'fridge', 'מקרר'],
        ['washing machine', 'מכונת כביסה'],
        ['iron', 'מגהץ'],
        ['drill', 'מקדחה'],
        ['grill', 'bbq', 'מנגל', 'גריל'],
        ['shaver', 'מכונת גילוח', 'גילוח'],
        ['toothbrush', 'מברשת שיניים'],
        ['hair dryer', 'פן', 'מייבש שיער'],
        ['massage', 'עיסוי', 'מסאז\''],
        ['lamp', 'מנורה', 'תאורה'],
        ['chair', 'כיסא', 'כסא'],
        ['desk', 'שולחן'],
        ['mattress', 'מזרן', 'מזרון'],
        ['pillow', 'כרית'],
        ['towel', 'מגבת'],
    ],
];
