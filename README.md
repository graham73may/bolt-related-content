# Bolt Related content extension

Retrieves a weighted array of similar content based on a configurable set of taxonomies and fields. Also has the ability to combine with a manual related content selection field. 

## Usage

Default usage:

    {{ relatedcontent(record) }}

Add options for more flexibility:

    {{ relatedcontent(record, { 'limit' : 5 }) }}

Default values are defined in `config.yml`. Use these options to override these settings.

By default, this extensions searches through all available contenttypes. Use `contenttypes` in `options` or in `config.yml` to filter specific contenttypes:

    {{ relatedcontent(record, { 'contenttypes' : [ 'news', 'events' ] }) }}

Non-existing contenttypes will be ignored.


## Twig example

The results from this extension are similar to how listings are handled in Bolt.

Add the following in your template for a simple example.

    {% for item in relatedcontent(record) %}
        <p><a href="{{ item.link }}">{{ item.title|e }}</a></p>
    {% endfor %}


## Options

See `config.yml` for more information. Options include:

* `limit` : the maximum number of results returned
* `contenttypes` : An array of contenttypes to search for.
* `taxonomies` : An array of taxonomies to use to find similar content and weigh results
* `fields` : An array of fields to use to find similar content and weigh results
* `manual_related_content_field` : A string of a field name which can be used to manually select related content.
* `manual_related_content_field_type` : The way the manual related content field is stored in the database.

### Limit
The `limit:` value is the number of items returned by the `relatedcontent()` twig function.

### ContentTypes
The `contenttypes:` option can be used to define which contenttypes automatic related content is selected from. e.g.:

```
contenttypes: [ news, research, events ]
```

If a `contenttypes:` option is not defined, **all** contenttypes will be used. 

Manual items can be from whatever contenttype your field allows the user to select.

### Taxonomies
The `taxonomies:` option can be used to define which taxonomies automatic related content is selected from. (These contribute to the where parameters for the getContent call). e.g.

```
taxonomies: [ tags, categories ]
```

If a `taxonomies:` option is not defined, all taxonomies will be used.

### Fields
The `fields:` option can be used to define which fields automatic related content is selected from. (These contribute to the where parameters for the getContent call). e.g. 

```
fields: [ blog_series ]
```

You can define a `searchweight` on a field in `contenttypes.yml` to adjust weighting for different fields. E.g. In contenttypes.yml:

```
blog_series:
    label: "Blog series"
    searchweight: 100
    type: select
    values: [ 'Blogs on food', 'Blogs on drink' ]
```

Fields are only used for comparison if they exist on the current record of which you are trying to find related content for. 

For example, you can define fields called `venue`, `blog_series` and `company` to be used by this extension but if the current item is a blog post and only has `blog_series` then the `venue` and `company` fields will be ignored (as they have no value from the current item).

If a `fields:` option is not defined, **no** fields will be used.


## Combining with manual related content

**This is an advanced feature.** 

This extension has the ability to take in to account a field for manually selecting related content and combining them with the automatically selected related content.

The way this works is as follows:
- Manual related content is always shown before auto related content
- Manual related content is shown in the order it is manually selected, whereas auto content is ordered by its weighting
- Manual related content is not weighed
- The number of auto related content shown is: (limit - number of manual items)

### Manual field example 

Example manual field config (from contenttypes.yml) using the RelationList extension:
```yml
related_content:
    group: "Related Content"
    label: "Related content"
    type: relationlist
    options:
        allowed-types: [ pages, news, commentseries, resources, research, projects, events, eventseries, people, jobs, charts, media ]
        min: 0
        max: 6
    postfix: "<p>Related content will be automatically generated based on categories and tagging if this field is left empty or not enough pieces are manually defined.</p>"
```

Example relatedcontent.soapbox.yml config for the above manual field:

```ymml
manual_related_content_field: related_content
manual_related_content_field_type: json
```

## Notes

This extension was built for a particular project, with that in mind - you may encounter bugs when using this in the wild, if you do please submit an issue or a PR!
