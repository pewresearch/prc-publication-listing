# PRC Publication Listing

A WordPress plugin that provides the default query handler for publication listings and post visibility controls for PRC Platform.

## Overview

### What This Plugin Does

The Publication Listing plugin serves as the central query manager for all publication-related content on the PRC Platform. It:

1. **Manages Query Context**: Automatically detects when a page is a "publication listing" (home, archives, search) and applies consistent query modifications
2. **Controls Post Visibility**: Provides a taxonomy-based system for hiding posts from specific contexts (index pages, search results)
3. **Extends Block Editor**: Adds visibility toggle controls to the post status panel
4. **Modifies Query Blocks**: Enhances `core/query` blocks with additional context and default settings

### Why It Exists

PRC Platform hosts multiple content types (reports, fact sheets, quizzes, features, etc.) that all need to appear in unified publication listings. Without centralized query management, each page template or query block would need to manually include all relevant post types and apply visibility rules. This plugin solves that by:

- Providing a single source of truth for which post types appear in listings
- Automatically applying visibility filtering to all relevant queries
- Ensuring consistent behavior across WP_Query, REST API, and block queries

### Key Concepts

| Term                    | Definition                                                                              |
| ----------------------- | --------------------------------------------------------------------------------------- |
| **Publication Listing** | Any query context that displays multiple publications (home, taxonomy archives, search) |
| **Post Visibility**     | A private taxonomy (`_post_visibility`) that controls where posts appear                |
| **Post Type Support**   | The WordPress-idiomatic way plugins declare their post types should appear in listings  |
| **isPubListingQuery**   | A query flag set by this plugin to identify publication listing contexts                |

## Architecture

```
prc-publication-listing/
├── prc-publication-listing.php    # Plugin entry point, constants
├── includes/
│   ├── class-bootstrap.php        # Plugin initialization, hook registration
│   ├── class-query.php            # Core query logic, taxonomy registration
│   ├── class-loader.php           # Hook management utility
│   └── utils.php                  # Utility functions
├── src/
│   ├── post-visibility/           # Block editor visibility toggles
│   │   └── index.js
│   └── core-query/                # Query block modifications
│       └── index.js
└── build/                         # Compiled assets
```

### Query Flow

```
┌─────────────────────────────────────────────────────────────────────┐
│                         Request Comes In                            │
└─────────────────────────────────────────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│ init_pub_listing__wp_query() [pre_get_posts, priority 1]            │
│ • Checks if main query on primary site                              │
│ • Determines if home, archive, or search page                       │
│ • Sets isPubListingQuery flag on query                              │
└─────────────────────────────────────────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│ hook_pub_listing_args_into__wp_query() [pre_get_posts, priority 11] │
│ • If isPubListingQuery is true, applies get_filtered_query_args()   │
│ • Merges supported post types, visibility tax_query, etc.           │
└─────────────────────────────────────────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│ include_in_main_feed() [pre_get_posts, priority 11]                  │
│ • On main site feed (not comment feed): merges Query::get_enabled_  │
│   post_types() into the query so all prc-publication-listing types  │
│   appear in the main RSS/Atom feed                                   │
└─────────────────────────────────────────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    Query Executes with Modifications                │
└─────────────────────────────────────────────────────────────────────┘
```

### Main RSS / Atom feed

The same post types that declare `prc-publication-listing` support are merged into the **main** feed query via `Query::include_in_main_feed()` (`pre_get_posts`, priority 11). Plugins do not need a separate feed filter—`add_post_type_support( 'my-type', 'prc-publication-listing' )` is enough for inclusion in publication listings and in the main feed.

## Requirements

- WordPress 6.7+
- PHP 8.2+
- PRC Platform Core plugin

## Installation

This plugin is part of the PRC Platform monorepo and requires `prc-platform-core` to be installed and activated.

```bash
# From the platform root
npm install -w @prc/publication-listing
npm run build -w @prc/publication-listing
```

## API Reference

### PHP Functions

#### `Query::get_enabled_post_types()`

Returns all post types that have declared support for publication listings.

```php
/**
 * @return array Array of post type names (e.g., ['post', 'report', 'quiz'])
 */
public static function get_enabled_post_types(): array
```

**Example:**

```php
use PRC\Platform\Publication_Listing\Query;

$post_types = Query::get_enabled_post_types();
// Returns: ['post', 'report', 'fact-sheet', 'quiz', 'feature', 'collection']
```

#### `Query::get_filtered_query_args()`

Applies publication listing modifications to query arguments.

```php
/**
 * @param array      $query_args Existing query arguments
 * @param WP_Query   $query      The query object (nullable for REST contexts)
 * @return array     Modified query arguments
 */
public static function get_filtered_query_args( array $query_args, $query ): array
```

**Modifications applied:**

| Argument              | Value                                            | Condition                                 |
| --------------------- | ------------------------------------------------ | ----------------------------------------- |
| `post_type`           | Merged with supported types                      | Always                                    |
| `post_parent`         | `0`                                              | Non-search, no `showChildPosts` query var |
| `post_status`         | `'publish'`                                      | Always                                    |
| `ignore_sticky_posts` | `true`                                           | Always                                    |
| `tax_query`           | Excludes `hidden-on-index` or `hidden-on-search` | Based on search context                   |

**Example:**

```php
use PRC\Platform\Publication_Listing\Query;

// Get default publication listing args
$args = Query::get_filtered_query_args( [], null );

// Use in a custom query
$query = new WP_Query( $args );
```

### Filters

#### `prc_platform_pub_listing_default_args`

Modify the default publication listing query arguments after post type support and visibility rules are applied.

```php
/**
 * @param array    $args  The query arguments
 * @param WP_Query $query The query object (may be null for REST/block contexts)
 * @return array   Modified query arguments
 */
apply_filters( 'prc_platform_pub_listing_default_args', array $args, $query ): array
```

**Use cases:**

```php
// Add a post type conditionally (e.g., search-only)
add_filter( 'prc_platform_pub_listing_default_args', function( $args, $query ) {
    if ( ! empty( $args['s'] ) ) {
        $args['post_type'][] = 'staff-byline';
    }
    return $args;
}, 10, 2 );

// Modify ordering
add_filter( 'prc_platform_pub_listing_default_args', function( $args ) {
    $args['orderby'] = 'modified';
    return $args;
} );

// Add custom meta query
add_filter( 'prc_platform_pub_listing_default_args', function( $args ) {
    $args['meta_query'][] = array(
        'key'   => '_featured',
        'value' => '1',
    );
    return $args;
} );
```

### Actions

#### `init` (priority 5)

Registers default post type support. Hook here to add support before taxonomy registration.

```php
add_action( 'init', function() {
    add_post_type_support( 'my-custom-type', 'prc-publication-listing' );
}, 5 );
```

### Post Type Support

The plugin uses WordPress's native `post_type_supports` mechanism for declaring which post types appear in publication listings.

#### Adding Support

```php
// Method 1: Using add_post_type_support() (recommended)
add_action( 'init', function() {
    add_post_type_support( 'my-post-type', 'prc-publication-listing' );
}, 5 ); // Priority 5 ensures it runs before taxonomy registration

// Method 2: In register_post_type() supports array
register_post_type( 'my-post-type', array(
    'supports' => array(
        'title',
        'editor',
        'prc-publication-listing'
    ),
    // ... other args
) );
```

#### Checking Support

```php
if ( post_type_supports( 'my-post-type', 'prc-publication-listing' ) ) {
    // Post type is included in publication listings
}
```

#### What Support Provides

Post types with `prc-publication-listing` support automatically:

1. Appear in all publication listing queries
2. Have access to the `_post_visibility` taxonomy
3. Show the post visibility toggle panel in the block editor

### Query Variables

#### `showChildPosts`

URL query variable to include child posts in publication listings.

```php
// URL: /publications/?showChildPosts=true

// Or programmatically:
$args = array(
    'showChildPosts' => true,
);
```

By default, publication listings only show top-level posts (`post_parent = 0`). This query var overrides that behavior.

### REST API

The plugin extends REST API queries with the `isPubListingQuery` parameter.

```javascript
// Fetch posts with publication listing rules applied
wp.apiFetch({
	path: '/wp/v2/posts?isPubListingQuery=true',
});
```

## Post Visibility

### Taxonomy: `_post_visibility`

A private taxonomy for controlling post visibility in listings.

| Term             | Slug               | Effect                                               |
| ---------------- | ------------------ | ---------------------------------------------------- |
| Hidden on Index  | `hidden-on-index`  | Post excluded from `/publications` and archive pages |
| Hidden on Search | `hidden-on-search` | Post excluded from internal search results           |

### Block Editor UI

Posts with publication listing support show visibility toggles in the post status panel:

- **Hide on Publications Archive**: Toggles `hidden-on-index` term
- **Hide on Internal Search**: Toggles `hidden-on-search` term
- **Hide from Search Engines**: Toggles `noindex` (requires prc-schema-seo support)

### Programmatic Control

```php
// Hide a post from the index
wp_set_object_terms( $post_id, 'hidden-on-index', '_post_visibility', true );

// Hide from search
wp_set_object_terms( $post_id, 'hidden-on-search', '_post_visibility', true );

// Remove visibility restrictions
wp_remove_object_terms( $post_id, array( 'hidden-on-index', 'hidden-on-search' ), '_post_visibility' );

// Check visibility
$terms = wp_get_object_terms( $post_id, '_post_visibility', array( 'fields' => 'slugs' ) );
$is_hidden_on_index = in_array( 'hidden-on-index', $terms, true );
```

## Query Block Integration

### Namespace: `prc-block/pub-listing-query`

The plugin enhances `core/query` blocks that use the `prc-block/pub-listing-query` namespace.

```html
<!-- wp:query {"namespace":"prc-block/pub-listing-query"} -->
<!-- Publication listing rules will be applied automatically -->
<!-- /wp:query -->
```

### Block Context Extensions

The plugin adds additional context to `core/query` blocks:

- `prc-platform/block-area-context`
- `postId`
- `postType`

### Default Tax Query Relation

The plugin defaults `core/query` block tax queries to `OR` relation (inclusive) instead of `AND` (exclusive). This ensures posts matching any selected taxonomy term are shown, rather than requiring all terms.

## Common Use Cases

### Adding a New Post Type to Listings

```php
// In your plugin's main file or functions.php
add_action( 'init', function() {
    // Register your post type
    register_post_type( 'research-brief', array(
        'label'    => 'Research Briefs',
        'public'   => true,
        'supports' => array(
            'title',
            'editor',
            'thumbnail',
            'prc-publication-listing', // Opt into publication listings
        ),
    ) );
}, 10 );
```

### Conditional Post Type Inclusion

For post types that should only appear in specific contexts (e.g., search only):

```php
add_filter( 'prc_platform_pub_listing_default_args', function( $args, $query ) {
    // Only include staff bylines in search results
    if ( ! empty( $args['s'] ) ) {
        $args['post_type'][] = 'staff-byline';
    }
    return $args;
}, 10, 2 );
```

### Creating a Custom Publication Query

```php
use PRC\Platform\Publication_Listing\Query;

// Get filtered args with all publication listing rules
$args = Query::get_filtered_query_args( array(
    'posts_per_page' => 10,
    'category_name'  => 'politics',
), null );

$query = new WP_Query( $args );
```

### Hiding Posts Programmatically

```php
// On publish, hide collection posts from index by default
add_action( 'prc_platform_on_publish', function( $post ) {
    if ( 'collection' === $post->post_type ) {
        wp_set_object_terms( $post->ID, 'hidden-on-index', '_post_visibility' );
    }
} );
```

## Best Practices

1. **Use post type support over filters** when your post type should always appear in listings. The filter is for conditional inclusion only.

2. **Register support at priority 5** to ensure it's available before the taxonomy is registered at priority 10.

3. **Don't modify `post_type` in filters if using support** - the plugin already handles merging supported types.

4. **Use the `isPubListingQuery` flag** when you need to detect publication listing context in your own hooks:

```php
add_action( 'pre_get_posts', function( $query ) {
    if ( $query->get( 'isPubListingQuery' ) ) {
        // We're in a publication listing context
    }
} );
```

## Common Pitfalls

### Post Type Not Appearing in Listings

**Problem:** Your post type doesn't show up in publication listings.

**Solution:** Ensure you've added support at the correct priority:

```php
// ❌ Wrong: Default priority runs after taxonomy registration
add_action( 'init', function() {
    add_post_type_support( 'my-type', 'prc-publication-listing' );
} );

// ✅ Correct: Priority 5 runs before taxonomy registration
add_action( 'init', function() {
    add_post_type_support( 'my-type', 'prc-publication-listing' );
}, 5 );
```

### Visibility Panel Not Showing

**Problem:** The block editor visibility toggles don't appear.

**Cause:** Post type doesn't have publication listing support.

**Solution:** Add support as shown above.

### Child Posts Not Showing

**Problem:** Hierarchical post types' child posts don't appear in listings.

**Cause:** By default, `post_parent = 0` is set to show only top-level posts.

**Solution:** Use the `showChildPosts` query var or filter the args:

```php
add_filter( 'prc_platform_pub_listing_default_args', function( $args ) {
    unset( $args['post_parent'] );
    return $args;
} );
```

### Query Block Not Applying Rules

**Problem:** A `core/query` block isn't using publication listing rules.

**Cause:** The block doesn't have the correct namespace.

**Solution:** Set the namespace attribute to `prc-block/pub-listing-query`:

```html
<!-- wp:query {"namespace":"prc-block/pub-listing-query"} -->
```

## Development

```bash
# Start development mode with hot reloading
npm run start -w @prc/publication-listing

# Build for production
npm run build -w @prc/publication-listing

# Run tests (from monorepo root; wp-env, Playground, and Playwright are centralized)
npm run env:start && npm test -- tests/prc-publication-listing/

# Lint PHP
composer phpcs -- plugins/prc-publication-listing

# Lint JavaScript
npm run lint:js -w @prc/publication-listing
```

## Changelog

### 1.1.0

- Migrated to `post_type_supports` mechanism for declaring supported post types
- Filter `prc_platform_pub_listing_default_args` now used for conditional inclusion only
- Added `Query::get_enabled_post_types()` static method

### 1.0.0

- Initial extraction from prc-platform-core
- Post visibility taxonomy and controls
- Query handler for publication listings
- Block editor integration
- REST API support
