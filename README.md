# ElasticParser V3

A Parser for ElasticSearch Front Request

## Summary
- [Features](#features)
- [How it works](#how-it-works)
- [Entry Points](#entry-points)
    - [Params Part](#params-parts-parameters)
    - [Query Part](#query-parts-parameters)
    - [Rules](#rules)
- [End Points](#end-points)
- [ElasticResultParser](#elasticresultparser)
- [Annexes](#annexes)
    - [Researchs](#researchs)
    - [RoadMap](#roadmap)
    - [Backend Search Tests](#backend-search-tests)

## Features

- Geo search (longitude, latitude)
- Simple search (word / multiple words)
- Field search (only on this/those field(s))
- Keyword search (exact syntax)
- Operator search (AND/OR/NOT/XOR)
- Multi parameters search ([A OR B] AND NOT C => 2 rules next to each other)

## How it works

The Parser is divided into 3 main parts :
- ES Params (`index`, `size`, `from`, ...)
- ES Query
- ES Highlights

Each part has is own way of work. The Query is the main part, using recursivity to build the query with all the `rules`.

## Entry Points

A simple exemple of a Front Request :

```json
{
  "debug" : false,
  "verbose" : false,
  "params" : {
    "usedFields": [
    ]
  },
  "query": {
    "rules": [
      {
        "field": null,
        "isKeyword": false,
        "operator": null,
        "values": [
          "never gonna give yo*"
        ]
      }
    ]
  }
}
```

So, as you can see, the request have two distinct parts :
- The Parameters Section, where you have all of the Elastic Search's Params ( Ex. Max size of the result )
- The Query Section, where you have all of the Front User's Search params ( Ex. The simple query string, the filtered fields, ... )

With those two parts, the values "debug" and "verbose" can be used for debugging and have directy the ES result, without the ElasticResultParser result.

### Params Part's Parameters

- `from` (int, default=0) : Number of pages of results skipped in the search, each page is of the size of `size` results
- `size` (int, default=20) : Maximun number of occurences passed 
- `usedFields` (array, default=[]) : List of fields used for a simple search (no `field` or `operator` in the rule -> See [Rules](#rules))

### Query Part's Parameters

- `geo` (object, default=null) : Use this part if you have a geographic search from a point
    - `longitude` (float, default=0.0) : Search point's longitude
    - `latitude` (float, default=0.0) : Search point's latitude
    - `maxDistanceKm` (int, default=20, optional) : Maximum distance from the point's length of search (in Km)
    - `order` (enum['asc', 'desc'], default='asc', optional) : Order of the results (normally ascending all the time)
- `rules` (object, default=[]) : Let's do a short explanation on how to use this part, and its structure ~

### Rules

The structure itself is pretty simple : `rules` is an array of `rule`. This is the structure of a `rule` :
- `rule` (object, default=[`values`])
    - `field` (string, default="", optional)
    - `isKeyword` (bool, default=false)
    - `operator` (string, default="", optional)
    - `values` (array)

Each rule adds a limitation to the search, and adding more rules in the query limits the result. There are 3 ways to use a rule :

- Simple Search
- Field Search
- Operator Search

In a `rule` object, if you only have a single value in the `values` array, the parser is going to use the value as the main search. You can use wildcard such as n-carac (*) and single-cara (?) wildcards. If you dont use them, then you'll only find the exact search.
```json
rules : [
    {
        "values": ["antropomor*"],
        "isKeyword": false
    }
]

-> Searching as "antropomor*"
```
**Only ONE rule such as this is allowed.** Only one search at a time. ( for my future self : Don't be dumb :D )

If you have a value, but also a `field`, then the search will be only on the selected field.
```json
rules : [
    {
        "field" : "phone",
        "isKeyword": false,
        "values" : ["+331234567890"]
    }
]

-> Searching "+331234567890" ONLY in all "phone" fields
```
In addition to that, you can make the `isKeyword` boolean as `true` if you want an Unanalyzed search (Case and Accent Sensitive + Space Sensitive + HTML Sensitive + Ponctuation Sensitive).

If you want to have some operations done between two values (OR / AND / XOR / NOT), then you just need to add the `operation` option.
```json
rules : [
    {
        "field" : "companyType",
        "isKeyword": false,
        "operator" : "OR",
        "values" : [
            "SARL",
            "SSII"
        ]
    }
]

-> Searching in all "societyType" fields for "SARL" OR "SSII"
```

Of course, the objective of the rules mechanism is to be able to make multiple restrictions in a single search. You can add as many rules as you want.

## End Points

I dont think i need to explain the whole system of ES query, but, depending of what type of rule you add, the Parser is going to add different type of parts in the ES query :
- A Simple Search is adding a `must -> query_string : VALUE` part. [[Query String Docs](https://www.elastic.co/guide/en/elasticsearch/reference/8.11/query-dsl-query-string-query.html)]
- A Field Search is adding a `must -> query_string : FIELD + VALUE` part [[Query String Docs](https://www.elastic.co/guide/en/elasticsearch/reference/8.11/query-dsl-query-string-query.html)]
- An Operator Search is adding :

|Operator |ES Query Part                                                                 |
|:-------:|:----------------------------------------------------------------------------:|
|AND      |`must -> {query_string -> FIELD : VAL1}, {query_string -> FIELD : VAL2}]`     |
|NOT      |`must_not -> [{query_string -> FIELD : VAL1}, {query_string -> FIELD : VAL2}]`|
|XOR      |`should -> {query_string -> FIELD : VAL1}, {query_string -> FIELD : VAL2}]`   |
|OR       |`should -> {query_string -> FIELD : VAL1}, {query_string -> FIELD : VAL2}]`   |

As you can see, for the XOR and the OR Operator, its exacly the same, but the XOR Operator add another option : `minimum_should_match = -1`. That means that the result can only have one of the two values as, not both in the field.

With the add of Nested Highlights, the query is far more complex than the first version. The query search for each part of the Nested Result : sites, sites.contacts, sites.contacts.phones, sites.contacts.emails, contacts, contacts.phones, contacts.emails. You can find an exemple down in the annexe's part.

## ElasticResultParser

When the result came back from ES, we used another parser to serialize all datas to the same architecture. Each field in the result is like this :

```json
[name_of_the_field] : {
  "content" : "sas les ambroisies",
  "isHighlighted" : true,
  "highlights" : {
    "sas" : 1,
    "ambroisies" : 1
  },
}
```

The type of `content` can be of type string, interger of float, depending of the value inside. If a field is of the type Array, the structure became an array :

```json
[name_of_the_field] : [
  {
    "content" : "0987654321",
    "isHighlighted" : false,
    "highlights" : null,
  }
  {
    "content" : "0123456789",
    "isHighlighted" : true,
    "highlights" : {
      "0123456789" : 1
    },
  }
]
```

## Annexes
### Researchs

ES Documentation :
- [Highlights](https://www.elastic.co/guide/en/elasticsearch/reference/current/highlighting.html)
- [Nested Highlights - Unofficial](https://stackoverflow.com/questions/53827467/highlight-nested-object-in-elasticsearch)
- [Nested Hits](https://www.elastic.co/guide/en/elasticsearch/reference/8.11/inner-hits.html)
- [How to Update ES Document](https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-update-by-query.html)
- [How to add Insensitive Case + Accent](https://discuss.elastic.co/t/accent-insensitive-search/218404)
- [ES Parentality Easy Explained - Unofficial](https://rockset.com/blog/can-i-do-sql-style-joins-in-elasticsearch/)
- [Speeeeeed the search (Optimisation)](https://www.elastic.co/guide/en/elasticsearch/reference/8.11/tune-for-search-speed.html)
- [Multiple Types on a single field (Text & Keyword) - Unofficial](https://stackoverflow.com/questions/58145815/keyword-searching-and-filtering-in-elasticsearch)
- [Operator Combination in ES Search - Unofficial](https://stackoverflow.com/questions/28538760/elasticsearch-bool-query-combine-must-with-or)

Basic Explications :
- [Basics of ES Dev - Unofficial](https://codecurated.com/blog/basics-of-elasticsearch-for-developer/)
- [Keyword VS Text - Unofficial](https://codecurated.com/blog/elasticsearch-text-vs-keyword/)
- [Intro to Analyzer - Unofficial](https://codecurated.com/blog/introduction-to-analyzer-in-elasticsearch/)

### RoadMap

- Keyword [DONE]
- Simple Search [DONE]
- Field Search [DONE]
- Operator Search [DONE]
- Highlight [DONE]
- Recursive Highlight (Nested Highlights) [DONE]

### Backend Search Tests

Simple Search
- "a"
- "rh"
- "SAS" / "sas"
- "alzu" / "alzuyeta" / "AlZuYetA"
- "11330" / "(11330)"
- "banks" / "canejj"
- "0442540689" Tel / "13770" Zip

Error Search
- "   " / "***" -> All result
- "(" / ")" -> Real Error ES -> Escape Brackets in the query