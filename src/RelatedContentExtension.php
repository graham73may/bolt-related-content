<?php

namespace Bolt\Extension\Soapbox\RelatedContent;

use Bolt\Extension\SimpleExtension;
use Bolt\Legacy\Content;
use Bolt\Storage\Query\QueryResultset;
use Bolt\Storage\Collection\Taxonomy;

/**
 * RelatedContent extension class.
 */
class RelatedContentExtension extends SimpleExtension
{

    /**
     * @var Content $record
     */

    // Current item
    private $record;
    private $current_contenttype;
    private $current_taxonomies;
    private $all_current_taxonomy_terms = [];

    // Related content settings
    private $options;
    private $allowed_contenttypes;
    private $allowed_fields;
    private $allowed_taxonomies;
    private $where_query_key = '';
    private $where_query_value = '';

    // Related content store
    private $manual_results = [];
    private $weighted_results = [];
    private $auto_results = [];

    /**
     * @inheritdoc
     * @return array
     */
    protected function registerTwigFunctions()
    {

        return [
            'relatedcontent' => 'getRelatedContent',
        ];
    }

    /**
     * Pretty extension name
     *
     * @return string
     */
    public function getDisplayName()
    {

        return 'Related Content';
    }

    /**
     * @param Content $record  The record to search similar content for.
     * @param array   $options Options for custom queries.
     *
     * @return array Returns an array with the elements sorted by similarity.
     */
    function getRelatedContent($record, $options = [])
    {

        // Save parameters to class
        $this->record  = $record;
        $this->options = $options;

        // Details about the current record
        $this->current_contenttype = $record->contenttype;
        $this->current_taxonomies  = $record->taxonomy;

        // Number of related items to return
        $limit = $this->getConfigValue('limit');

        /**
         * @var array $related_content
         */
        // Get Manually related content
        $related_content = $this->getManualRelatedContent();

        // Does the manual content fulfil are limit requirements?
        if (count($related_content) < $limit) {
            $auto_related_content = $this->getAutoRelatedContent();

            // Remove duplicates and merge with manual
            $related_content = array_merge($related_content, $this->removeDuplicateResults($related_content, $auto_related_content));
        }

        // Remove current item from results
        // (`id != n` doesn't exist for multiple contenttypes queries at present)
        $related_content = $this->excludeCurrentFromResults($related_content);

        // Limit results
        $related_content = array_slice($related_content, 0, $limit);

        // Return the results array
        return $related_content;
    }

    /**
     * Get a record's manually defined related content
     *
     * @return array Returns an array of manually selected related content
     */
    private function getManualRelatedContent()
    {

        $manual_field = $this->getConfigValue('manual_related_content_field');

        $manual_related_content = [];

        if (!empty($manual_field)) {
            $manual_related_content_value = $this->record->offsetGet($manual_field);

            if (!empty($this->current_contenttype['fields'][$manual_field])) {
                $app = $this->getContainer();

                switch ($this->getManualFieldType()) {
                    case 'array':
                        // Aimed at select fields where multiple = true
                        $target_type_order = $this->current_contenttype['fields'][$manual_field]['values'];
                        $target_type       = current(explode("/", $target_type_order));

                        // Get the content items for the id's in the array
                        if (!empty($manual_related_content_value) && is_array($manual_related_content_value)) {
                            foreach ($manual_related_content_value as $id) {
                                $content = $app['query']->getContent($target_type . '/' . $id);

                                if (!empty($content) && $content->get('status') === 'published') {
                                    $manual_related_content[] = $content;
                                }
                            }
                        }

                        break;

                    case 'relationlist':
                    case 'json':
                        // Specifically tested to work with the RelationList extension
                        $array = json_decode($manual_related_content_value, true);

                        // Get the content items stored in the relationList field
                        if (!empty($array) && is_array($array)) {
                            foreach ($array as $query) {
                                $content = $app['query']->getContent($query);

                                if (!empty($content) && $content->get('status') === 'published') {
                                    $manual_related_content[] = $content;
                                }
                            }
                        }

                        break;

                    case 'single':
                    default:
                        // Aimed at select fields where multiple = false
                        $target_type_order = $this->current_contenttype['fields'][$manual_field]['values'];
                        $target_type       = current(explode("/", $target_type_order));

                        // Get the single content item
                        $content = $app['query']->getContent($target_type . '/' . $manual_related_content_value);

                        if (!empty($content) && $content->get('status') === 'published') {
                            $manual_related_content = $content;
                        }

                        break;
                }
            }
        }

        $this->manual_results = $manual_related_content;

        return $manual_related_content;
    }

    /**
     * Get a record's related content dependant on taxonomy similarities
     *
     * @return array Returns an array of automatically selected related content
     */
    private function getAutoRelatedContent()
    {

        $app = $this->getContainer();

        // Config details
        $allowed_contenttypes = $this->getAllowedContenttypes();
        $allowed_taxonomies   = $this->getAllowedTaxonomies();
        $allowed_fields       = $this->getAllowedFields();

        // Prepare the query strings
        // Contenttypes string
        $query_contenttypes_string = $this->getQueryContentTypesString($allowed_contenttypes);

        // Add Taxonomies to the where array
        $this->getQueryTaxonomyWhereArray($allowed_taxonomies);

        // Add Fields to the where array
        $this->getQueryFieldsWhereArray($allowed_fields);

        // Build the where query
        // If there's no where query, there's no information to get related content by
        if (!empty($this->where_query_key) && !empty($this->where_query_value)) {
            // Compose the where array
            $where_query           = [$this->where_query_key => $this->where_query_value];
            $where_query['status'] = 'published';

            // Query to DB
            $results = $app['query']->getContent($query_contenttypes_string, $where_query);

            // Weigh the results and sort by weighting
            $this->weighResults($results);
        }

        // Remove the weighting, flatten, return ready for merge
        $this->flattenWeightedResults();

        return $this->auto_results;
    }

    private function getConfigValue($key)
    {

        $value = null;

        // Check the config
        if (isset($this->getConfig()[$key])) {
            $value = $this->getConfig()[$key];
        }

        // Check if has been passed as an option
        if (isset($this->options[$key])) {
            $value = $this->options[$key];
        }

        return $value;
    }

    private function getQueryContentTypesString($contenttypes)
    {

        $string = '';

        if (!empty($contenttypes)) {
            if (count($contenttypes) === 1) {
                $string = array_keys($contenttypes)[0];
            } else {
                $string = '(';
                $string .= implode(array_keys($contenttypes), ',');
                $string .= ')';
            }
        }

        return $string;
    }

    private function getQueryTaxonomyWhereArray($taxonomies)
    {

        $query_array = [];

        if (!empty($taxonomies)) {
            foreach ($taxonomies as $slug => $values) {
                $taxonomy_values = array_values($values);

                if (!empty($taxonomy_values)) {
                    $query_array[$slug] = implode($taxonomy_values, ' || ');

                    $this->addToWhereValue($query_array[$slug]);
                }
            }

            $this->addToWhereKey(implode(array_keys($query_array), ' ||| '));
        }

        return $query_array;
    }

    private function getQueryFieldsWhereArray($fields)
    {

        $query_array = [];

        if (!empty($fields)) {
            foreach ($fields as $slug => $values) {
                switch ($values['type']) {
                    // Support for ReleationList extension
                    case 'relationlist':
                        $field_values = json_decode($values['value'], true);

                        // Wrap in like query
                        array_walk($field_values, function (&$value) {

                            $value = '%' . $value . '%';
                        });

                        $query_array[$slug] = $field_values;
                        $query_string       = implode($field_values, ' || ');
                        break;

                    default:
                        $field_values       = $values['value'];
                        $query_array[$slug] = $field_values;

                        $query_string = $field_values;
                        break;
                }

                $this->addToWhereValue($query_string);
            }

            $this->addToWhereKey(implode(array_keys($query_array), ' ||| '));
        }

        return $query_array;
    }

    private function addToWhereKey($string)
    {

        if (empty($this->where_query_key)) {
            $this->where_query_key .= $string;
        } else {
            $this->where_query_key .= ' ||| ' . $string;
        }
    }

    private function addToWhereValue($string)
    {

        if (empty($this->where_query_value)) {
            $this->where_query_value .= $string;
        } else {
            $this->where_query_value .= ' ||| ' . $string;
        }
    }

    /**
     * Get an array of contenttypes that should be checked for content related to the current item
     * - Defaults to all contenttypes
     * - Config value `contenttypes` can be used to define which contenttypes to check
     *
     * @return array Array of contenttypes that can be checked for related content
     */
    private function getAllowedContenttypes()
    {

        // App
        $app = $this->getContainer();

        // Get configs
        $all_contenttypes    = $app['config']->get('contenttypes');
        $config_contenttypes = $this->getConfigValue('contenttypes');

        // Use all contenttypes
        $allowed_contenttypes = $all_contenttypes;

        if (!empty($config_contenttypes)) {
            // If config has been defined, remove any that are not included
            foreach ($all_contenttypes as $key => $type) {
                if (!in_array($key, $config_contenttypes)) {
                    unset($allowed_contenttypes[$key]);
                }
            }
        }

        $this->allowed_contenttypes = $allowed_contenttypes;

        return $this->allowed_contenttypes;
    }

    /**
     * Check fields that should be taken into consideration when calculating a result's weight
     *
     * @return array Array of fields to calculate weight for
     */
    private function getAllowedFields()
    {

        // Fields defined in config
        $config_fields = $this->getConfigValue('fields');

        // Array to store fields in
        $allowed_fields = [];

        if (isset($config_fields)) {
            // Get the fields for the current record
            $current_fields = $this->record->values;

            foreach ($config_fields as $field) {
                // Check array key in the config exists in the current items fields
                // Skip if the field is empty (No point in comparing)
                if (array_key_exists($field, $current_fields) && !empty($current_fields[$field])) {
                    $allowed_fields[$field] = [
                        'type'  => $this->record->contenttype['fields'][$field]['type'],
                        'value' => $current_fields[$field]
                    ];
                }
            }
        }

        $this->allowed_fields = $allowed_fields;

        return $this->allowed_fields;
    }

    private function getAllowedTaxonomies()
    {

        if (!empty($this->current_taxonomies)) {
            // App
            $app = $this->getContainer();

            // Get configs
            $all_taxonomies    = $app['config']->get('taxonomy');
            $config_taxonomies = $this->getConfigValue('taxonomies');

            // Start with all Taxonomies
            $allowed_taxonomies = $all_taxonomies;

            if (!empty($config_taxonomies)) {
                // If config has been defined, remove any that are not included
                foreach ($all_taxonomies as $key => $type) {
                    if (!in_array($key, $config_taxonomies)) {
                        unset($allowed_taxonomies[$key]);
                    }
                }
            } else if ($config_taxonomies !== null) {
                $allowed_taxonomies = [];
            }

            $queryable_taxonomies = array_intersect_key($this->current_taxonomies, $allowed_taxonomies);
        } else {
            $queryable_taxonomies = [];
        }

        $this->allowed_taxonomies = $queryable_taxonomies;

        return $this->allowed_taxonomies;
    }

    /**
     * Weigh a full QueryResultset
     *
     * @param QueryResultset $results
     *
     * @return array
     */
    private function weighResults($results)
    {

        $result_set = $results->get('results');

        // Setup the taxonomy array for the current record
        $this->buildCurrentTaxonomyTermsArray();

        if (!empty($result_set)) {
            foreach ($result_set as $result) {
                $weighed_result = $this->weighResult($result);

                $this->weighted_results[] = $weighed_result;
            }

            // Sort the results by the weight
            $this->sortWeightedResults();
        }

        return $this->weighted_results;
    }

    private function sortWeightedResults()
    {

        usort($this->weighted_results, function ($a, $b) {

            return $b['weight'] - $a['weight'];
        });

        return $this->weighted_results;
    }

    /**
     * @return array
     */
    private function flattenWeightedResults()
    {

        $this->auto_results = array_column($this->weighted_results, 'record');

        return $this->auto_results;
    }

    private function buildCurrentTaxonomyTermsArray()
    {

        $all_current_taxonomy_terms = [];

        // Loop through each taxonomy type
        if (!empty($this->allowed_taxonomies) && is_array($this->allowed_taxonomies)) {
            foreach ($this->allowed_taxonomies as $tax_slug => $taxonomy) {
                $all_current_taxonomy_terms = array_merge($all_current_taxonomy_terms, [$tax_slug => array_keys($taxonomy)]);
            }
        }

        $this->all_current_taxonomy_terms = $all_current_taxonomy_terms;

        return $this->all_current_taxonomy_terms;
    }

    /**
     * Weigh an individual result
     *
     * @param \Bolt\Storage\Entity\Content $result
     *
     * @return mixed
     */
    private function weighResult($result)
    {

        $weight = 0;

        // Compare taxonomies
        $weight = $this->calculateTaxonomyWeight($result, $weight);

        // Compare fields
        $weight = $this->calculateFieldWeight($result, $weight);

        // Add the weight to the return
        $weighed_result = [
            'weight' => $weight,
            'record' => $result
        ];

        return $weighed_result;
    }

    /**
     * Calculate the weight value depending on the results taxonomies
     *
     * @param \Bolt\Storage\Entity\Content $result
     * @param int                          $weight
     *
     * @return int
     */
    private function calculateTaxonomyWeight($result, $weight = 0)
    {

        // Store of taxonomy terms for the result that is being weighed
        $all_result_taxonomy_terms = [];

        // Loop through each taxonomy type to build an array of the result's taxonomy terms
        foreach ($this->allowed_taxonomies as $tax_slug => $taxonomy) {
            // Get the taxonomy from the result
            /**
             * @var Taxonomy $result_taxonomy
             */
            $result_taxonomy = $result->getTaxonomy();

            // Get the taxonomy term objects
            $result_taxonomy_terms = $result_taxonomy->getValues();

            // Get the term values
            $result_taxonomy_term_values = array_column($result_taxonomy_terms, 'slug');

            // Modify the term values to prepend the taxonomy slug
            // This will then match the taxonomy array for the current item
            array_walk($result_taxonomy_term_values, function (&$value) use ($tax_slug) {

                $value = '/' . $tax_slug . '/' . $value;
            });

            // Add to array of all result taxonomy terms
            $all_result_taxonomy_terms = array_merge($all_result_taxonomy_terms, [$tax_slug => $result_taxonomy_term_values]);
        }

        // Loop through each current taxonomy terms array and check how it compares to the result item
        // Use the array comparison to calculate to weighting score
        foreach ($this->all_current_taxonomy_terms as $current_taxonomy => $current_taxonomy_terms) {
            // Get the weighting for this taxonomy
            $tax_weight = $this->getTaxWeight($current_taxonomy);

            // Compare the results taxonomy array to the current item array
            $intersect = array_intersect($current_taxonomy_terms, $all_result_taxonomy_terms[$current_taxonomy]);

            // How many things match?
            $size = count($intersect);

            // Calculate the weight for this taxonomy
            $weight += ($size * $tax_weight);
        }

        return $weight;
    }

    /**
     * Calculate the weight value depending on the results fields
     *
     * @param \Bolt\Storage\Entity\Content $result
     * @param int                          $weight
     *
     * @return int
     */
    private function calculateFieldWeight($result, $weight = 0)
    {

        $result_contenttype_slug = $result->getContenttype()['slug'];
        $result_fields           = array_keys($result->_fields);

        $all_result_field_values = [];

        // Loop through each field and create an array to compare the result fields against the current item
        foreach ($this->allowed_fields as $field_slug => $field_info) {
            if (in_array($field_slug, $result_fields)) {
                $result_field_weight = $this->getFieldWeight($result_contenttype_slug, $field_slug);
                $result_field_value  = $result->_fields[$field_slug];
                $result_field_type   = $result->getContenttype()['fields'][$field_slug]['type'];

                $all_result_field_values[$field_slug] = [
                    'type'   => $result_field_type,
                    'value'  => $result_field_value,
                    'weight' => $result_field_weight
                ];
            }
        }

        foreach ($all_result_field_values as $field_slug => $field_info) {
            if (in_array($field_slug, $this->allowed_fields) && $field_info['type'] === $this->allowed_fields[$field_slug]['type']) {
                if ($field_info['value'] === $this->allowed_fields[$field_slug]['value']) {
                    $weight += $field_info['weight'];
                }
            }
        }

        return $weight;
    }

    /**
     * Retrieve a taxonomies searchweight value
     *
     * @param $slug
     *
     * @return int
     */
    private function getTaxWeight($slug)
    {

        // App
        $app = $this->getContainer();

        // Get configs
        $tax_config = $app['config']->get('taxonomy');

        if (array_key_exists('searchweight', $tax_config[$slug])) {
            $tax_weight = intval($tax_config[$slug]['searchweight'], 10);
        } else {
            $tax_weight = 50;
        }

        return $tax_weight;
    }

    /**
     * Retrieve a fields searchweight value
     *
     * @param $contenttype_slug
     * @param $field_slug
     *
     * @return int
     */
    private function getFieldWeight($contenttype_slug, $field_slug)
    {

        // App
        $app = $this->getContainer();

        // Get configs
        $ct_config = $app['config']->get('contenttypes')[$contenttype_slug];

        if (array_key_exists('searchweight', $ct_config['fields'][$field_slug])) {
            $ct_weight = intval($ct_config['fields'][$field_slug]['searchweight'], 10);
        } else {
            $ct_weight = 50;
        }

        return $ct_weight;
    }

    private function getManualFieldType()
    {

        $type = $this->getConfigValue('manual_related_content_field_type');

        return $type;
    }

    private function removeDuplicateResults($manual, $auto)
    {

        // Get the contenttype/id array of manually selected items
        $manual_map = array_map(function ($record) {

            /**
             * @var \Bolt\Storage\Entity\Content $record
             */
            return $record->getContenttype()['slug'] . '/' . $record->getId();
        }, $manual);

        // Remove auto results that are already in the manual array
        $results = array_filter($auto, function ($record) use ($manual_map) {

            /**
             * @var \Bolt\Storage\Entity\Content $record
             */
            $contenttype = $record->getContenttype()['slug'];
            $id          = $record->getId();

            return (!in_array($contenttype . '/' . $id, $manual_map));
        });

        return array_values($results);
    }

    private function excludeCurrentFromResults($results)
    {

        $current_item = $this->current_contenttype['slug'] . '/' . $this->record->get('id');

        // Remove auto results that are already in the manual array
        $results = array_filter($results, function ($record) use ($current_item) {

            /**
             * @var \Bolt\Storage\Entity\Content $record
             */
            $contenttype = $record->getContenttype()['slug'];
            $id          = $record->getId();

            return (($contenttype . '/' . $id !== $current_item));
        });

        return array_values($results);
    }
}
