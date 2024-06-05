<?php

/**
 * The ElasticParser service is responsible for params Elasticsearch.
 */
class ElasticParser
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

	private array $highlights = [
		"highlight" => [
			"pre_tags" => [''],
			"post_tags" => [''],
			"order" => "score",
			"fields" => [
				"*" => [
					'fragment_size' => 1,
					'number_of_fragments' => 3,
					'fragmenter' => 'span'
				]
			]
		]
	];

	// This is the data architecture. You need to change it as the same as the mapping of your ES Index
	private array $toConstruct = [
		"part1" => [
			"part1.part11" => [
                "part1.part11.part111" => null,
                "part1.part11.part112" => null
			]
		],
		"part2" => [
			"part2.part21" => null,
			"part2.part22" => null
		]
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
	 * Get Data Array
	 *
	 * @return mixed
	 */
	public function getData(): mixed
	{
		return $this->data;
	}

	/**
	 * Get Config Array
	 *
	 * @return mixed
	 */
	public function getConfig(): mixed
	{
		return $this->config;
	}

	/**
	 * Return the result of the Parser
	 *
	 * @return mixed
	 */
	public function getResult(): mixed
	{
		return $this->result;
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
     * Transform data into readable JSON for ElasticSearch
     *
     * @return bool
     */
	public function transformData(): bool
    {
        // Params
        $this->constructParams();

		//// Search construction
		return $this->constructBody();
    }

    /**
     * Construct the 'params' part of the ElasticSearch Query
     *
     * @return void
     */
	public function constructParams(): void
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
     * Construct the 'query' part of the ElasticSearch Query
     *
     * @return bool
     */
	public function constructBody(): bool
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
	 * Build of the query part
	 *
	 * @param array $rules
	 * @return bool
	 */
	public function constructNestedQuery(array $rules): bool
	{
		$queryOperators = $this->constructOperators($rules);
		if ($queryOperators === false) {
			// Wildcard Error
			return false;
		}

		$this->result['body'] = $this->recursQueryConstruct($this->toConstruct, 'body', $queryOperators)['body'];

		return true;
	}

	/**
	 * Auto Construct Query
	 *
	 * @param array $path
	 * @param string $type
	 * @param array $queryOperators
	 * @return array
	 */
	public function recursQueryConstruct(array $path, string $type, array $queryOperators): array
	{
		$query = [];
		$toAdd = [];

		$toAdd[] = $queryOperators;

		foreach ($path as $key => $value) {
			if ($value !== null) {
				// Nested Path
				$temp = $this->recursQueryConstruct($value, 'nested', $queryOperators);
				$temp['nested']['path'] = $key;
				$temp['nested']['inner_hits'] = $this->highlights;
				if (!empty($queryOperators)) {
					$toAdd[] = $temp;
				}
			} else {
				if (!empty($queryOperators)) {
					$toAdd[]['nested'] = [
						'path' => $key,
						'query' => $queryOperators,
						'inner_hits' => $this->highlights
					];
				}
			}
		}

		// type 'body' -> End of the recursive
		if ($type === 'body') {
			$query[$type] = $this->highlights;
		}

		if (count($path) > 1) {
			if (!empty($queryOperators)) {
				$query[$type]['query']['bool']['should'] = $toAdd;
			}
		} else {
			if (!empty($queryOperators)) {
				$query[$type]['query'] = $toAdd;
			}
		}

		return $query;
	}

	/**
	 * Add all the restriction to the 'query' part of the ElasticSearch Query - Version 2
	 *
	 * @param mixed $rules
	 * @return array
	 */
	public function constructOperators(mixed $rules): array | bool
	{
		$linkedRules = [];
		foreach ($rules as $rule) {
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
			// Adding rules to their operator - NEED TO CHANGE for OR and XOR next to each other
			if ($rule['operator'] === 'XOR') {
				$linkedRules = $this->XOR($parsedRule);
			} else {
				if (isset($parsedRule['query_string'])) {
					$linkedRules['bool'][$operator][] = $parsedRule;
				} else {
					$linkedRules['bool'][$operator] = $parsedRule;
				}
			}
		}

		$result['bool']['must'] = $linkedRules;
		return $result;
	}

	/**
	 * Function to create XOR in query
	 *
	 * @param array $objects
	 * @return array
	 */
	public function XOR(array $objects): array
	{
		$res = [];
		$count = count($objects);

		$res['bool']['should'] = $objects;
		$res['bool']['must_not']['bool']['should'] = $objects;
		$res['bool']['must_not']['bool']['minimum_should_match'] = $count;

		return $res;
	}

	/**
	 * Construct the Bool Part (with Query String) of the query
	 *
	 * @param mixed $rule
	 * @return array|bool
	 */
	public function constructQueryString(mixed $rule): array | bool
	{
		$parsedFields = [];
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
				$parsedFields['query_string']['fields'][] = "*".$field.$keyword;

		} else {
			$parsedFields['query_string']['fields'] = ["*"];
		}

		// New query_string foreach value
		$parsedRule = [];
		foreach ($rule['values'] as $value) {
			// Protect search with only wildcard
			foreach(explode(" ", $value) as $word) {
				$counter = 0;
				$counter += substr_count($word, '*');
				$counter += substr_count($word, '?');
				if ($counter == strlen($word) && $counter != 0) {
					return false;
				}
			}

			// Escape Characters
			foreach (["\\", '(', ')','{', '}','[', ']', '~', '^', '"', '-', "'", "_", '+', '!', ':', '/'] as $toEscape) {
				$value = str_replace($toEscape, "\\" . $toEscape, $value);
			}

			$toAdd = $parsedFields;
			$toAdd['query_string']['query'] = $value;

			if (isset($parsedRule['query_string'])) {
				// First multi-argument
				$temp = $parsedRule;
				$parsedRule = [];
				$parsedRule[] = $toAdd;
				$parsedRule[] = $temp;
			} else {
				if ($parsedRule !== []) {
					$parsedRule[] = $toAdd;
				} else {
					$parsedRule = $toAdd;
				}
			}
		}


		return $parsedRule;
	}
}