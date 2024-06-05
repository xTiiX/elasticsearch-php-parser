# ElasticParser

A Parser for ElasticSearch Front Request

## Summary
- [How it works](#how-it-works)
- [Entry Points](#entry-points)
    - [Params Part](#params-parts-parameters)
    - [Query Part](#query-parts-parameters)
    - [Rules](#rules)
- [End Points](#end-points)
- [Annexes](#annexes)
    - [Researchs](#researchs)
    - [RoadMap](#roadmap)
    - [Backend Search Tests](#backend-search-tests)

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
  "params" : {
    "usedFields": [
    ]
  },
  "query": {
    "rules": [
      {
        "field": null,
        "operator": null,
        "values": [
          "never gonna give yo"
        ]
      }
    ]
  }
}
```

So, as you can see, the request have two distinct parts :
- The Parameters Section, where you have all of the Elastic Search's Params ( Ex. Max size of the result )
- The Query Section, where you have all of the Front User's Search params ( Ex. The simple query string, the filtered fields, ... )

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

In a `rule` object, if you only have a single value in the `values` array, then the parser is going to use the value as the main search, adding a wildcard at the end (*)
```json
rules : [
    {
        "values": ["antropomor"]
    }
]

-> Searching as "antropomor*"
```
**Only ONE rule such as this is allowed.** Only one search at a time. ( for my future self : Don't be dumb :D )

If you don't only have a value, but also a `field`, then the search will be only on the selected field. Be careful, this search isn't using a wildcard, so it would be searching only for the exact given value.
```json
rules : [
    {
        "field" : "phone",
        "values" : ["+331234567890"]
    }
]

-> Searching "+331234567890" ONLY in the "phone" field
```
In addition to that, you can make the `isKeyword` boolean as `true` if you want an Unanalyzed search (Case and Accent Sensitive + Space Sensitive + HTML Sensitive + Ponctuation Sensitive). The field need to have a field.keyword field with type `keyword` to work though.

If you want to have some operations done between two values (OR / AND / XOR / NOT), then you just need to add the `operation` option. Be carful, this search also isn't using wildcard (*), so it is only searching for the exact given value.
```json
rules : [
    {
        "field" : "companyType",
        "operator" : "OR",
        "values" : [
            "SARL",
            "SSII"
        ]
    }
]

-> Searching in the field "societyType" for "SARL" OR "SSII"
```

Of course, the objective of the rules mechanism is to be able to make multiple restrictions in a single search. You can add as many rules as you want.

## End Points

I dont think i need to explain the whole system of ES query, but, depending of what type of rule you add, the Parser is going to add different type of parts in the ES query :
- A Simple Search is adding a `must -> query_string : VALUE` part. [[Query String Docs](https://www.elastic.co/guide/en/elasticsearch/reference/8.11/query-dsl-query-string-query.html)]
- A Field Search is adding a `must -> term -> FIELD : VALUE` part [[Term Docs](https://www.elastic.co/guide/en/elasticsearch/reference/8.11/query-dsl-term-query.html)]
- An Operator Search is adding :

|Operator |ES Query Part                                                 |
|:-------:|:------------------------------------------------------------:|
|AND      |`must -> {term -> FIELD : VAL1}, {term -> FIELD : VAL2}]`     |
|NOT      |`must_not -> [{term -> FIELD : VAL1}, {term -> FIELD : VAL2}]`|
|XOR      |`should -> {term -> FIELD : VAL1}, {term -> FIELD : VAL2}]`   |
|OR       |`should -> {term -> FIELD : VAL1}, {term -> FIELD : VAL2}]`   |

As you can see, for the XOR and the OR Operator, its exacly the same, but the XOR Operator add another option : `minimum_should_match = -1`. That means that the result can only have one of the two values as, not both in the field.

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
- Operator Search [ON GOING] -> Need to use the Highlight Dico to properly do Term Field's search
- Recursivity (Operator inside a value of an operator) [DONE]
- Highlight [DONE]
- Recursive Highlight (Nested Highlights) [TO DO]

### Save ES Query for tests

```json
  {
    "query": {
      "bool": {
        "must": [
          {
            "query_string": {
              "query": "(*1* AND bi*) OR Wov*",
              "fields": [
                "*phoneValue",
                "registeredName",
                "*city"
              ]
            }
          }
        ]
      }
    },
    "highlight": {
      "pre_tags": "<span class=\"highlighted\">",
      "post_tags": "</span>",
      "order": "score",
      "fields": {
        "*": {
          "fragment_size": 12,
          "number_of_fragments": 3,
          "fragmenter": "span"
        }
      }
    }
  }

  ///////////// Escaped Caracters Search

  {
    "query": {
      "bool": {
        "must": [
          {
            "query_string": {
              "query": "Alzu* \\(*0",
              "fields": [
                "*phoneValue",
                "registeredName",
                "*city"
              ]
            }
          }
        ]
      }
    },
    "highlight": {
      "pre_tags": "<span class=\"highlighted\">",
      "post_tags": "</span>",
      "order": "score",
      "fields": {
        "*": {
          "fragment_size": 12,
          "number_of_fragments": 3,
          "fragmenter": "span"
        }
      }
    }
  }

  ///////////// Nested Search

  {
    "query": {
      "bool": {
        "should": [
          {
            "query_string": {
              "query": "controltec*",
              "fields": ["*"]
            }
          },
          {
            "nested": {
              "path": "contacts",
              "query": {
                "bool": {
                  "should": [
                    {
                      "query_string": {
                        "query": "controltec*",
                        "fields": ["*"]
                      }
                    },
                    {
                      "nested": {
                        "path": "contacts.emails",
                        "query": {
                          "bool": {
                            "must": [
                              {
                                "query_string": {
                                  "query": "controltec*",
                                  "fields": ["*"]
                                }
                              }
                            ]
                          }
                        }, 
                        "inner_hits": {
                          "highlight": {
                            "fields": {
                              "*": {
                                "fragment_size": 12,
                                "number_of_fragments": 3,
                                "fragmenter": "span"
                              }
                            }
                          }
                        }
                      }
                    },
                    {
                      "nested": {
                        "path": "contacts.phones",
                        "query": {
                          "bool": {
                            "must": [
                              {
                                "query_string": {
                                  "query": "controltec*",
                                  "fields": ["*"]
                                }
                              }
                            ]
                          }
                        }, 
                        "inner_hits": {
                          "highlight": {
                            "fields": {
                              "*": {
                                "fragment_size": 12,
                                "number_of_fragments": 3,
                                "fragmenter": "span"
                              }
                            }
                          }
                        }
                      }
                    }
                  ]
                }
              }, 
              "inner_hits": {
                "highlight": {
                  "fields": {
                    "*": {
                      "fragment_size": 12,
                      "number_of_fragments": 3,
                      "fragmenter": "span"
                    }
                  }
                }
              }
            }
          },
          {
            "nested": {
              "path": "sites",
              "query": {
                "bool": {
                  "should": [
                    {
                      "query_string": {
                        "query": "controltec*",
                        "fields": ["*"]
                      }
                    },
                    {
                      "nested": {
                        "path": "sites.contacts",
                        "query": {
                          "bool": {
                            "should": [
                              {
                                "query_string": {
                                  "query": "controltec*",
                                  "fields": ["*"]
                                }
                              },
                              {
                                "nested": {
                                  "path": "sites.contacts.emails",
                                  "query": {
                                    "bool": {
                                      "must": [
                                        {
                                          "query_string": {
                                            "query": "controltec*",
                                            "fields": ["*"]
                                          }
                                        }
                                      ]
                                    }
                                  }, 
                                  "inner_hits": {
                                    "highlight": {
                                      "fields": {
                                        "*": {
                                          "fragment_size": 12,
                                          "number_of_fragments": 3,
                                          "fragmenter": "span"
                                        }
                                      }
                                    }
                                  }
                                }
                              },
                              {
                                "nested": {
                                  "path": "sites.contacts.phones",
                                  "query": {
                                    "bool": {
                                      "must": [
                                        {
                                          "query_string": {
                                            "query": "controltec*",
                                            "fields": ["*"]
                                          }
                                        }
                                      ]
                                    }
                                  }, 
                                  "inner_hits": {
                                    "highlight": {
                                      "fields": {
                                        "*": {
                                          "fragment_size": 12,
                                          "number_of_fragments": 3,
                                          "fragmenter": "span"
                                        }
                                      }
                                    }
                                  }
                                }
                              }
                            ]
                          }
                        }, 
                        "inner_hits": {
                          "highlight": {
                            "fields": {
                              "*": {
                                "fragment_size": 12,
                                "number_of_fragments": 3,
                                "fragmenter": "span"
                              }
                            }
                          }
                        }
                      }
                    }
                  ]
                }
              }, 
              "inner_hits": {
                "highlight": {
                  "fields": {
                    "*": {
                      "fragment_size": 12,
                      "number_of_fragments": 3,
                      "fragmenter": "span"
                    }
                  }
                }
              }
            }
          }
        ]
      }
    }
  }
```

### Backend Search Tests

Simple Search
- "a"
- "rh"
- "SAS" / "sas"
- "alzu" / "alzuyeta" / "AlZuYetA"
- "11330" / "(11330)"

Error Search
- "   " / "***" -> All result
- "(" / ")" -> Real Error ES -> Escape Brackets in the query