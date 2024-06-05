<?php

class elasticParser {
    private mixed $data;
    private mixed $result;
    private bool $debug;
    private array $defaultParameters = [
        "size" => 20,
        "from" => 0,
        "fields" => [], // Searching in all the fields that the index have
    ];

    // Used for Highlight part
    private array $usedFields = [];

    /**
     * Do a parsing from a Front-type JSON to a ElasticSearch-type JSON Query
     *
     * @param string $file_name
     * @param string $index
     * @param bool $debug
     */
    public function __construct(string $file_name, string $index = 'contacts', bool $debug = false)
    {
        $this->debug = $debug;
        if ($debug) {
            printf("--- DEBUG MODE ---\n");
        }
        $this->constructData($file_name, $index);
        $this->transformData();
        $this->printResult($file_name);
    }

    /**
     * Construct the SearchRequest into a data table
     *
     * @param string $filename
     * @param string $index
     * @return void
     */
    private function constructData(string $filename, string $index = 'contacts'): void
    {
        $json = "";
        $file = fopen('./json_tests/'.$filename, "r") or die("Unable to open file!");

        while(!feof($file)) {
            $json .= fgets($file);
        }
        fclose($file);
        $this->data = json_decode($json, true);
        $this->data['params']['index'] = $index;
        printf("Front JSON Parsed.\n");

        // debug
        /*if ($this->debug) {
            //printf($json . "\n");
            var_dump($this->data);
            printf("\n");
        }*/
    }

    /**
     * Print the JSON in a file
     *
     * @param string $filename
     * @return void
     */
    private function printResult(string $filename): void {
        ////// Send JSON (Here just print/write for the example)
        file_put_contents('./json_results/result_'.$filename, json_encode($this->result, JSON_PRETTY_PRINT));
        printf("-------- Back JSON \n");
        var_dump($this->result);
        printf("--------\n\n");
    }

    /**
     * Transform data into readable JSON for ElasticSearch
     *
     * @return void
     */
    private function transformData(): void
    {
        // Params
        $this->constructParams();

        //// Search construction
        $this->constructQuery();
        // Fields
        // Terms Module (A special query for a special term)
        // OR Module (must)
        // AND Module (should)
        // NOT Module (must_not)
        // XOR Module (should + minimum_should_match = -1) --> MAX 2 VALUES
        // https://stackoverflow.com/questions/28538760/elasticsearch-bool-query-combine-must-with-or

        // Highlights
        $this->constructHighlight();

        ////// Save the actual used ElasticSearch JSON, so we can prev and next with this
        /// static var with the data version
    }

    /**
     * Contruct the 'params' part of the ElasticSearch Query
     *
     * @return void
     */
    private function constructParams(): void
    {
        $this->result['index'] = $this->data['params']['index'];
        $this->result['size'] = $this->data['params']['size'] ?? $this->defaultParameters['size'];
        // Offset by the number of items 'per page' using size
        if (isset($this->data['params']['from']) && $this->data['params']['from'] != 0)
            $this->result['from'] = isset($this->data['params']['from']) ?
                $this->data['params']['from'] * $this->result['size'] :
                $this->defaultParameters['from'];

        if (isset($this->data['query']['rules'])) {
            // Add the body and query part
            $this->result['body']['query'] = [];
        }
    }

    /**
     * Contruct the 'query' part of the ElasticSearch Query
     *
     * @return void
     */
    private function constructQuery(): void
    {
        //Geo case
        if (isset($this->data['query']['geo'])) {
            printf("Geo Search Add\n");
            $this->result['body']['sort'][] = [
                "_geo_distance" => [
                    "pin.location" => isset($this->data['query']['geo']) ?
                        [$this->data['query']['geo']['longitude'] ?? 0, $this->data['query']['geo']['latitude'] ?? 0] :
                        [0, 0],
                    "order" => $this->data['query']['geo']['order'] ?? "asc",
                    "distance" => $this->data['query']['geo']['maxDistanceKm'] ?? 20,
                    "unit" => "km",
                    "mode" => "min",
                    "distance_type" => "arc",
                    "ignore_unmapped"=> true
                ]
            ];
        }

        // build the 'query' part of the request
        if (isset($this->data['query']['rules'])) {
            $this->result['body']['query'] = $this->recursOperator($this->data['query']['rules']);
        }
    }

    /**
     * Add all the restriction to the 'query' part of the ElasticSearch Query
     *
     * @param mixed $actualObject
     * @return array
     */
    private function recursOperator(mixed $actualObject): array
    {
        // Initialize vars
        $toAdd = null;
        $mustAdd = $mustNotAdd = $shouldAdd = [];
        $minShould = null; // Number of Should that need to match in the response (in this bool scope)

        foreach ($actualObject as $rule) {
            $values = [];

            // Check what part of the rule is set of not
            $partSet = [
                'field' => isset($rule['field']),
                'operator' => isset($rule['operator']),
                'values' => isset($rule['values'])
            ];
            /*if ($this->debug) {
                printf("Part Set Rule :\n");
                var_dump($partSet);
                var_dump($rule);
            }*/

            // Keyword Search
            if (isset($rule['field']) && $rule['isKeyword']) {
                $rule['field'] .= '.keyword';
            }

            if (!$partSet['operator']) {
                if (!$partSet['field'] && $partSet['values']) {
                    // Simple Search
                    if ($this->debug)
                        printf("Simple Search\n");
                    $mustAdd['query_string'] = [
                        'query' => isset($rule['values'][0]) ?
                            $rule['values'][0] . '*' :
                            '',
                        'fields' => $this->data['params']['usedFields'] ?? $this->defaultParameters['fields'],
                    ];
                } else {
                    $this->usedFields[] = $rule['field'];
                    // Term Module
                    if ($this->debug)
                        printf("Term Add\n");
                    $mustAdd = $this->addTerms($mustAdd, $rule);
                }
            }

            // Go deeper if needed - Values
            if ($partSet['values'] && is_array($rule['values'])) {
                foreach ($rule['values'] as $value) {
                    if (is_array($value)) {
                        // Is another rule
                        if ($this->debug) {
                            printf("Recursive add\nTO ADD ------------->");
                            var_dump($value);
                        }
                        $values[] = $this->recursOperator([$value]);
                    } else {
                        $values[] = $value;
                    }
                }
                $rule['values'] = $values;
            }

            // Operators
            if ($partSet['operator']) {
                $this->usedFields[] = $rule['field'];
                switch ($rule['operator']) {
                    case 'AND':
                        if ($this->debug)
                            printf("Operator AND\n");
                        $mustAdd = $this->addTerms($mustAdd, $rule);
                        break;
                    case 'NOT':
                        if ($this->debug)
                            printf("Operator NOT\n");
                        $mustNotAdd = $this->addTerms($mustNotAdd, $rule);
                        break;
                    case 'XOR':
                        if ($this->debug)
                            printf("OperatorXOR\n");
                        $minShould = -1; // use only one of the two value in the should
                    case 'OR':
                        if ($this->debug && $rule['operator'] != "XOR")
                            printf("Operator OR\n");
                        $shouldAdd = $this->addTerms($shouldAdd, $rule);
                        break;
                    default:
                        break;
                }
            }
        }

        // Bool build to return
        if (!empty($mustAdd))
            $toAdd['bool']['must'][] = $mustAdd;
        if (!empty($shouldAdd))
            $toAdd['bool']['should'][] = $shouldAdd;
        if (!empty($mustNotAdd))
            $toAdd['bool']['must_not'][] = $mustNotAdd;
        if (!empty($minShould))
            $toAdd['bool']['minimum_should_match'] = $minShould;

        return $toAdd;
    }

    /**
     * Add a term to the list of restrictions
     *
     * @param array $toAdd
     * @param array $rule
     * @return array
     */
    private function addTerms(array $toAdd, array $rule): array
    {
        foreach ($rule['values'] as $value) {
            $termSet = false;
            $counter = 0;
            foreach ((is_array($toAdd) ? $toAdd : []) as $terms) {
                // toAdd[];
                foreach ((is_array($terms) ? $terms : []) as $term) {
                    // $toAdd[][];
                    if ($this->debug) {
                        printf("---\n");
                        var_dump($termSet);
                        var_dump($term);
                        printf("---\n");
                    }
                    if (!$termSet && !isset($term[$rule['field']])) {
                        // $toAdd[]['terms']
                        if ($this->debug)
                            printf("--> AddAdd - Counter ".$counter."\n");
                        $toAdd[$counter]['terms'][$rule['field']] = $value;
                        $termSet = true;
                    }
                    $counter += 1;
                }
            }
            if (!$termSet) {
                if ($this->debug)
                    printf("--> NewAdd\n");
                $toAdd[]['terms'][$rule['field']] = $value;
            }
            /*if ($this->debug) {
                printf("Rule ->\n");
                var_dump($rule);
                printf("-------\n\nToAdd ->\n");
                var_dump($toAdd);
                printf("-------\n\nValue ->\n");
                var_dump($value);
                printf("-------\n\n");
            }*/
        }

        return $toAdd;
    }

    /**
     * Add all the 'Highlight' part in the search query
     *
     * @return void
     */
    private function constructHighlight() {
        // No filtered query -> All fields
        if ($this->usedFields === []) {
            $this->result['body']['highlight'] = [
              "require_field_match" => false,
              "fields" => [
                  "body" => [
                      "pre_tags" => ['<span class="highlighted">'],
                      "post_tags" => ["</span>"]
                  ]
              ]
            ];
            return ;
        }

        // Filtered query -> Adding highlights for all the filtered fields
        $this->result['body']['highlight'] = [
            "pre_tags" => ['<span class="highlighted">'],
            "post_tags" => ["</span>"],
            "order" => "score"
        ];
        foreach ($this->usedFields as $usedField) {
            if (!isset($this->result['body']['highlight'][$usedField])) {
                $this->result['body']['highlight'][$usedField] = [
                    "type" => "plain",
                    "fragment_size" => 15,
                    "number_of_fragment" => 3,
                    "fragmenter" => "span"
                ];
            }
        }
    }
}


////////////////////////////////// V2
class ElasticParserV2
{
	private mixed $data;
	private mixed $result;
	private mixed $config;

	private array $operatorTrad = [
		"OR" => 'should',
		"XOR" => 'should',
		"AND" => 'must',
		"NOT" => 'must_not',
	];

	/**
	 * Do a parsing from a Front-type JSON to a ElasticSearch-type JSON Query
	 *
	 * @param array $data
	 * @param array $config
	 */
	public function __construct(array $data, array $config)
	{
		// Construct the data + config
		$this->data = $data;
		$this->config = $config;
		$this->data['params']['index'] = $this->config["elastic"]['ELASTIC_MAPPING']['index'];
	}

	/**
	 * Parse all the data into a
	 *
	 * @return bool
	 */
	public function parseData(): bool
	{
		// Parse every data
		if (!$this->transformData()) {
			// Only Wildcard Error
			return false;
		}
		return true;
	}

	/**
	 * Return the result of the Parser
	 *
	 * @return array
	 */
	public function getResult(): array {
		return $this->result;
	}

	/**
	 * Transform data into readable JSON for ElasticSearch
	 *
	 * @return void
	 */
	private function transformData(): bool
	{
		// Params
		$this->constructParams();

		//// Search construction
		if (!$this->constructQuery()) {
			return false;
		}
		// Fields
		// Terms Module (A special query for a special term)
		// OR Module (must)
		// AND Module (should)
		// NOT Module (must_not)
		// XOR Module (should + minimum_should_match = -1) --> MAX 2 VALUES
		// https://stackoverflow.com/questions/28538760/elasticsearch-bool-query-combine-must-with-or

		// Highlights
		$this->constructHighlight();
		return true;
	}

	/**
	 * Contruct the 'params' part of the ElasticSearch Query
	 *
	 * @return void
	 */
	private function constructParams(): void
	{
		$this->result['index'] = $this->data['params']['index'];
		$this->result['size'] = $this->data['params']['size'] ?? $this->config['elastic']['ELASTIC_SIZE'];
		// Offset by the number of items 'per page' using size
		if (isset($this->data['params']['from']) && $this->data['params']['from'] != 0)
			$this->result['from'] = isset($this->data['params']['from']) ?
				$this->data['params']['from'] * $this->result['size'] :
				0;

		if (isset($this->data['query']['rules'])) {
			// Add the body and query part
			$this->result['body']['query'] = [];
		}
	}

	/**
	 * Contruct the 'query' part of the ElasticSearch Query
	 *
	 * @return void
	 */
	private function constructQuery(): bool
	{
		//Geo case
		if (isset($this->data['query']['geo'])) {
			$this->result['body']['sort'][] = [
				"_geo_distance" => [
					"pin.location" => isset($this->data['query']['geo']) ?
						[$this->data['query']['geo']['longitude'] ?? 0, $this->data['query']['geo']['latitude'] ?? 0] :
						[0, 0],
					"order" => $this->data['query']['geo']['order'] ?? "asc",
					"distance" => $this->data['query']['geo']['maxDistanceKm'] ?? 20,
					"unit" => "km",
					"mode" => "min",
					"distance_type" => "arc",
					"ignore_unmapped"=> true
				]
			];
		}

		// build the 'query' part of the request
		if (isset($this->data['query']['rules'])) {
			return $this->constructNestedQuery($this->data['query']['rules']);
		}
		return true;
	}

	/**
	 * Add all the restriction to the 'query' part of the ElasticSearch Query
	 *
	 * @param mixed $actualObject
	 * @return array
	 */
	private function recursOperator(mixed $actualObject): array
	{
		// Initialize vars
		$toAdd = null;
		$mustAdd = $mustNotAdd = $shouldAdd = [];
		$minShould = null; // Number of Should that need to match in the response (in this bool scope)

		foreach ($actualObject as $rule) {
			$values = [];

			// Check what part of the rule is set of not
			$partSet = [
				'field' => isset($rule['field']),
				'operator' => isset($rule['operator']),
				'values' => isset($rule['values'])
			];

			// Keyword Search
			if (isset($rule['field']) && $rule['isKeyword']) {
				$rule['field'] .= '.keyword';
			}

			if (!$partSet['operator']) {
				if (!$partSet['field'] && $partSet['values']) {
					// Simple Search
					$mustAdd['query_string'] = [
						'query' => isset($rule['values'][0]) ?
							$rule['values'][0] . '*' :
							'',
						'fields' => $this->data['params']['usedFields'] ?? [],
					];
				} else {
					// Term Module
					$mustAdd = $this->addTerms($mustAdd, $rule);
				}
			}

			// Go deeper if needed - Values
			if ($partSet['values'] && is_array($rule['values'])) {
				foreach ($rule['values'] as $value) {
					if (is_array($value)) {
						// Is another rule
						$values[] = $this->recursOperator([$value]);
					} else {
						$values[] = $value;
					}
				}
				$rule['values'] = $values;
			}

			// Operators
			if ($partSet['operator']) {
				switch ($rule['operator']) {
					case 'AND':
						$mustAdd = $this->addTerms($mustAdd, $rule);
						break;
					case 'NOT':
						$mustNotAdd = $this->addTerms($mustNotAdd, $rule);
						break;
					case 'XOR':
						$minShould = -1; // use only one of the two value in the should
					case 'OR':
						$shouldAdd = $this->addTerms($shouldAdd, $rule);
						break;
					default:
						break;
				}
			}
		}

		// Bool build to return
		if (!empty($mustAdd))
			$toAdd['bool']['must'] = $mustAdd;
		if (!empty($shouldAdd))
			$toAdd['bool']['should'] = $shouldAdd;
		if (!empty($mustNotAdd))
			$toAdd['bool']['must_not'] = $mustNotAdd;
		if (!empty($minShould))
			$toAdd['bool']['minimum_should_match'] = $minShould;

		return $toAdd;
	}

	/**
	 * Build of the query part
	 *
	 * @param array $rules
	 * @return array|bool
	 */
	private function constructNestedQuery(array $rules): bool
	{
		$queryOperators = $this->constructOperators($rules);
		if ($queryOperators === false) {
			// Wildcard Error
			return false;
		}

		// Construct Highlights
		$highlights = [
			"pre_tags" => ['<span class="highlighted">'],
			"post_tags" => ["</span>"],
			"order" => "score"
		];
		$highlights['fields']['*'] = [
			'fragment_size' => 12,
			'number_of_fragments' => 3,
			'fragmenter' => 'span'
		];

		// Nested Contacts Path
		$emails['nested'] = [
			'path' => "sites.contacts.emails",
			'query' => $queryOperators,
			'inner_hits' => $highlights
		];
		$phones['nested'] = [
			'path' => "sites.contacts.phones",
			'query' => $queryOperators,
			'inner_hits' => $highlights
		];
		$nestedContacts['nested'] = [
			'path' => "sites.contacts",
			'inner_hits' => $highlights
		];
		$nestedContacts['query']['bool']['should'][] = $queryOperators;
		$nestedContacts['query']['bool']['should'][] = $emails;
		$nestedContacts['query']['bool']['should'][] = $phones;

		// Sites Path
		$sites['nested'] = [
			'path' => "sites",
			'inner_hits' => $highlights
		];
		$sites['query']['bool']['should'][] = $queryOperators;
		$sites['query']['bool']['should'][] = $nestedContacts;

		// Contacts Path
		$contacts['nested'] = $nestedContacts;
		$contacts['nested']['path'] = 'contacts';

		// Base Path
		$this->result['body']['query']['bool']['should'][] = $queryOperators;
		$this->result['body']['query']['bool']['should'][] = $sites;
		$this->result['body']['query']['bool']['should'][] = $contacts;
		$this->result['body']['highlights'] = $highlights;

		return true;
	}

	/**
	 * Add all the restriction to the 'query' part of the ElasticSearch Query - Version 2
	 *
	 * @param mixed $actualObject
	 * @return array
	 */
	private function constructOperators(mixed $actualObject): array | bool
	{
		$linkedRules = [];
		foreach ($actualObject as $rule) {
			// Construct the rule
			$parsedRule = $this->constructQueryString($rule);
			if ($parsedRule === false) {
				// Wildcard Error
				return false;
			}

			// Assign the rule to its operator
			if ($rule['operator'] === null) {
				// Simple Search
				$operator = 'must';
			} else {
				// Operator Search
				$operator = $this->operatorTrad[$rule['operator']];
			}
			if (is_array($linkedRules['bool'][$operator])) {
				$linkedRules['bool'][$operator][] = $parsedRule;
			} else {
				$linkedRules['bool'][$operator] = $parsedRule;
			}
		}

		return $linkedRules;
	}

	/**
	 * Construct the Bool Part (with Query String) of the query
	 *
	 * @param mixed $rule
	 * @return array|bool
	 */
	private function constructQueryString(mixed $rule): array | bool
	{
		$parsedRule = [];
		// Field Search (One / Multi)
		if (isset($rule['field'])) {
			$keyword = "";
			// Adding Keyword
			if ($rule['isKeyword'] === true) {
				$keyword = ".keyword";
			}

			// Adding Fields
			if (!is_array($rule['field'])) {
				$rule['field'] = [$rule['field']];
			}
			foreach ($rule['field'] as $field)
				$parsedRule['query_string']['fields'][] = "*".$field.$keyword;

		} else {
			$parsedRule['query_string']['fields'] = ["*"];
		}

		// Protect search with only wildcard
		$counter = 0;
		foreach(explode(" ", $rule['values'][0]) as $word) {
			$counter += substr_count($word, '*');
			$counter += substr_count($word, '?');
			if ($counter == strlen($word)) {
				return false;
			}
		}

		// Simple Search (Word / Full Quote)
		$parsedRule['query_string']['query'] = $rule['values'][0];
		foreach (['(', ')', '~', '^', '"'] as $toEscape) {
			$parsedRule['query_string']['query'] = str_replace($toEscape, "\\" . $toEscape, $parsedRule['query_string']['query']);
			error_log($parsedRule['query_string']['query']);
		}

		return $parsedRule;
	}

	/**
	 * Add a term to the list of restrictions
	 *
	 * @param array $toAdd
	 * @param array $rule
	 * @return array
	 */
	private function addTerms(array $toAdd, array $rule): array
	{
		foreach ($rule['values'] as $value) {
			$termSet = false;
			$counter = 0;
			foreach ((is_array($toAdd) ? $toAdd : []) as $terms) {
				// toAdd[];
				foreach ((is_array($terms) ? $terms : []) as $term) {
					// $toAdd[][];
					if (!$termSet && !isset($term[$rule['field']])) {
						// $toAdd[]['term']
						$toAdd[$counter]['term'][$rule['field']] = $value;
						$termSet = true;
					}
					$counter += 1;
				}
			}
			if (!$termSet) {
				$toAdd[]['term'][$rule['field']] = $value;
			}
		}

		return $toAdd;
	}

	/**
	 * Add all the 'Highlight' part in the search query
	 *
	 * @return void
	 */
	private function constructHighlight(): void {
		// Not nested results
		$this->result['body']['highlight'] = [
			"pre_tags" => ['<span class="highlighted">'],
			"post_tags" => ["</span>"],
			"order" => "score",
			"fields" => [
				"*" => [
					"fragment_size" => 12,
					"number_of_fragments" => 3,
					"fragmenter" => "span"
				]
			]
		];
	}
}

$parser = new elasticParser("basic_search.json", 'test', false);
printf("\n\nTest Geo\n\n");
$parser2 = new elasticParser("geo_search.json", 'test', false);
printf("\n\nTest Terms\n\n");
$parser2 = new elasticParser("terms_search.json", 'test', true);
printf("\n\nTest Complex\n\n");
$parser2 = new elasticParser("complex_search.json", 'test', false);
printf("\n\nTest Big Complex\n\n");
$parser2 = new elasticParser("big_complex_search.json", 'test', true);