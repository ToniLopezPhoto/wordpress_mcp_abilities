# WordPress MCP Abilities

## 1. Overview
- **Plugin name:** WordPress MCP Abilities
- **Purpose:** Secure WordPress Abilities layer for AI agent content management via MCP Adapter
- **Version:** 0.16.0
- **License:** GPL-2.0-or-later

## 2. Requirements
- WordPress >= 6.9
- PHP >= 7.4
- Plugin: mcp-adapter (WordPress/mcp-adapter)
- HTTPS recommended for Application Passwords

## 3. Architecture
```
wordpress-mcp-abilities/
├── wordpress-mcp-abilities.php       # Bootstrap
├── includes/
│   ├── class-plugin.php              # Orchestrator
│   ├── class-permissions.php         # Permission helpers (capability checks, validators)
│   ├── class-ability-schema.php      # Shared meta/annotations + pagination helpers
│   ├── class-ability-errors.php      # Centralized WP_Error factory
│   ├── class-ability-categories.php  # Registers all 15 MCP categories
│   ├── class-ability-matrix.php      # ability -> capability/meta-cap -> destructive -> idempotent
│   ├── class-ability-registry.php    # Orchestrates ability registration across domains
│   ├── class-content-lifecycle.php   # Shared post/page lifecycle logic (publish state, trash, author, slug, revisions)
│   ├── class-integration.php         # Integration adapter base class (detection, contract, exclusions)
│   ├── class-integration-registry.php# Adapter manifests, detection cache, conditional registration
│   ├── integrations/
│   │   ├── class-generic-integration.php            # Declarative detect-only adapter
│   │   ├── class-woocommerce-integration.php        # wp-mcp-extensibility: WooCommerce
│   │   ├── class-yoast-seo-integration.php          # wp-mcp-extensibility: Yoast SEO
│   │   ├── class-contact-form-7-integration.php     # wp-mcp-extensibility: Contact Form 7
│   │   ├── class-gravity-forms-integration.php      # wp-mcp-extensibility: Gravity Forms
│   │   └── class-wpforms-integration.php            # wp-mcp-extensibility: WPForms
│   ├── abilities/
│   │   ├── class-content-abilities.php             # wp-mcp-content: posts/pages core CRUD
│   │   ├── class-content-lifecycle-abilities.php    # wp-mcp-content: publish state, schedule, trash/restore/delete
│   │   ├── class-content-attributes-abilities.php   # wp-mcp-content: author, slug, sticky, password, page attributes
│   │   ├── class-content-revisions-abilities.php    # wp-mcp-content: revisions, autosave
│   │   ├── class-content-bulk-abilities.php         # wp-mcp-content: duplicate, bulk trash
│   │   ├── class-media-abilities.php                # wp-mcp-media
│   │   ├── class-taxonomies-abilities.php           # wp-mcp-taxonomies
│   │   ├── class-comments-abilities.php             # wp-mcp-comments
│   │   ├── class-users-abilities.php                # wp-mcp-users
│   │   ├── class-navigation-abilities.php           # wp-mcp-navigation: classic menus, locations, items
│   │   ├── class-site-editor-abilities.php          # wp-mcp-site-editor: FSE, patterns, wp_navigation
│   │   ├── class-settings-abilities.php             # wp-mcp-settings: explicit site settings
│   │   ├── class-integrations-abilities.php         # wp-mcp-extensibility: the integration layer itself
│   │   └── class-discovery-abilities.php            # wp-mcp-discovery: global search and registered objects
│   ├── class-posts.php               # Post callbacks
│   ├── class-pages.php               # Page callbacks
│   ├── class-media.php               # Media callbacks
│   ├── class-taxonomies.php          # Taxonomy callbacks
│   ├── class-comments.php             # Comment callbacks
│   ├── class-users.php                # User, role, and application-password callbacks
│   ├── class-navigation.php           # Classic navigation callbacks
│   ├── class-site-editor.php          # Templates, parts, patterns, wp_navigation, styles, widget areas
│   ├── class-settings.php             # Declarative settings allowlist (General/Writing/Reading/Discussion/Media/Permalinks/Privacy)
│   ├── class-discovery-contract.php   # Single source of truth: grammars, vocabularies, ceilings, MCP schemas
│   ├── class-discovery.php            # Global search, registered objects, capabilities, feature support
│   ├── class-role.php                 # Role management
│   └── class-audit.php               # Audit logging
├── tests/
│   └── test-abilities.php            # PHPUnit tests
├── README.md
└── uninstall.php
```

Adding a new ability domain (see epic #1's roadmap) means creating one
file under `includes/abilities/` and registering it in
`WP_MCP_Ability_Registry` — no existing registration logic, schema, or
permission helper needs to change. Adding a third-party *integration*
(issue #14) is smaller still: one adapter class under
`includes/integrations/` plus one manifest line in
`WP_MCP_Integrations`, which loads the file, decides whether the plugin
is present, and contributes the adapter's own permission-matrix rows.

## 4. Abilities Available (285 total)

### 4a. Posts — Core CRUD

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/list-posts` | Read | List published posts and own drafts/pending | None | status, search, page, per_page (max 50) | id, title, status, excerpt, author, date, modified, link | readonly=true, destructive=false, idempotent=true | read |
| `wp-mcp/get-post` | Read | Get a specific post | post_id | None | id, title, content, excerpt, status, author, categories, tags, featured_media, date, modified, link | readonly=true, destructive=false, idempotent=true | read |
| `wp-mcp/create-post` | Write | Create a new post as draft | title, content | excerpt, categories (IDs), tags (IDs) | id, title, status, excerpt, author, date, modified, link | readonly=false, destructive=false, idempotent=false | edit_posts |
| `wp-mcp/update-post` | Write | Update a post the current user can edit | post_id | title, content, excerpt, categories, tags | id, title, status, excerpt, author, date, modified, link | readonly=false, destructive=true, idempotent=false | edit_posts |
| `wp-mcp/publish-post` | Write | Publish a draft post the current user can edit | post_id | None | id, status, published_date, modified, link | readonly=false, destructive=true, idempotent=true | edit_posts, publish_posts |

### 4b. Posts — Lifecycle

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/unpublish-post` | Write | Move a published post back to draft | post_id | None | id, title, status, date, modified, link | readonly=false, destructive=true, idempotent=true | edit_posts |
| `wp-mcp/schedule-post` | Write | Schedule a post for future publication | post_id, date | None | id, title, status, date, modified, link | readonly=false, destructive=true, idempotent=true | edit_posts, publish_posts |
| `wp-mcp/change-post-status` | Write | Transition between draft and pending review only | post_id, status (draft/pending) | None | id, title, status, excerpt, author, date, modified, link | readonly=false, destructive=true, idempotent=true | edit_posts |
| `wp-mcp/trash-post` | Write | Move a post to trash (reversible) | post_id | None | id, title, status, date, modified, link | readonly=false, destructive=true, idempotent=true | edit_posts |
| `wp-mcp/restore-post` | Write | Restore a trashed post | post_id | None | id, title, status, date, modified, link | readonly=false, destructive=false, idempotent=true | edit_posts |
| `wp-mcp/delete-post-permanently` | Write | Permanently delete a post, bypassing trash | post_id | None | id, deleted | readonly=false, destructive=true, idempotent=false | delete_posts |

### 4c. Posts — Attributes

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/change-post-author` | Write | Reassign a post to a different author | post_id, new_author_id | None | id, title, status, date, modified, link | readonly=false, destructive=true, idempotent=true | edit_posts, edit_others_posts |
| `wp-mcp/update-post-slug` | Write | Change a post's URL slug | post_id, slug | None | id, title, status, date, modified, link | readonly=false, destructive=true, idempotent=true | edit_posts |
| `wp-mcp/stick-post` | Write | Pin a post to the front of the blog listing | post_id | None | id, title, status, excerpt, author, date, modified, link | readonly=false, destructive=false, idempotent=true | edit_posts, edit_others_posts |
| `wp-mcp/unstick-post` | Write | Remove a post's sticky flag | post_id | None | id, title, status, excerpt, author, date, modified, link | readonly=false, destructive=false, idempotent=true | edit_posts, edit_others_posts |
| `wp-mcp/set-post-password` | Write | Set or clear a post's access password | post_id, password | None | id, title, status, excerpt, author, date, modified, link | readonly=false, destructive=true, idempotent=true | edit_posts |

### 4d. Posts — Revisions & Autosave

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/list-post-revisions` | Read | List saved revisions for a post | post_id | page, per_page | revisions[], total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | edit_posts |
| `wp-mcp/get-post-revision` | Read | Get a single revision's full content | post_id, revision_id | None | id, parent_id, author, title, content, excerpt, date, modified | readonly=true, destructive=false, idempotent=true | edit_posts |
| `wp-mcp/restore-post-revision` | Write | Restore a post to a previous revision | post_id, revision_id | None | id, title, status, date, modified, link | readonly=false, destructive=true, idempotent=false | edit_posts |
| `wp-mcp/get-post-autosave` | Read | Get the current user's most recent autosave, if any | post_id | None | exists, id, author, title, content, excerpt, date, modified | readonly=true, destructive=false, idempotent=true | edit_posts |

### 4e. Posts — Duplication & Bulk

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/duplicate-post` | Write | Duplicate a post as a new draft owned by the current user | post_id | None | id, title, status, excerpt, author, date, modified, link | readonly=false, destructive=false, idempotent=false | edit_posts |
| `wp-mcp/bulk-trash-posts` | Write | Trash up to 20 posts in one request, reported per item | post_ids (max 20) | None | results[], trashed, failed | readonly=false, destructive=true, idempotent=true | edit_posts |

### 4f. Pages — Core CRUD

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/list-pages` | Read | List published pages | None | search, page, per_page | id, title, status, modified, link | readonly=true, destructive=false, idempotent=true | read |
| `wp-mcp/get-page` | Read | Get a specific page | page_id | None | id, title, content, status, modified, link | readonly=true, destructive=false, idempotent=true | read |
| `wp-mcp/create-page` | Write | Create a new page as draft | title, content | excerpt | id, title, status, excerpt, author, date, modified, link | readonly=false, destructive=false, idempotent=false | edit_posts |
| `wp-mcp/update-page` | Write | Update a page the current user can edit | page_id | title, content, excerpt | id, title, status, excerpt, author, date, modified, link | readonly=false, destructive=true, idempotent=false | edit_posts |

### 4g. Pages — Lifecycle

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/publish-page` | Write | Publish a draft page | page_id | None | id, title, status, date, modified, link | readonly=false, destructive=true, idempotent=true | edit_pages, publish_pages |
| `wp-mcp/unpublish-page` | Write | Move a published page back to draft | page_id | None | id, title, status, date, modified, link | readonly=false, destructive=true, idempotent=true | edit_pages |
| `wp-mcp/schedule-page` | Write | Schedule a page for future publication | page_id, date | None | id, title, status, date, modified, link | readonly=false, destructive=true, idempotent=true | edit_pages, publish_pages |
| `wp-mcp/trash-page` | Write | Move a page to trash (reversible) | page_id | None | id, title, status, date, modified, link | readonly=false, destructive=true, idempotent=true | edit_pages |
| `wp-mcp/restore-page` | Write | Restore a trashed page | page_id | None | id, title, status, date, modified, link | readonly=false, destructive=false, idempotent=true | edit_pages |
| `wp-mcp/delete-page-permanently` | Write | Permanently delete a page, bypassing trash | page_id | None | id, deleted | readonly=false, destructive=true, idempotent=false | delete_pages |

### 4h. Pages — Attributes

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/change-page-author` | Write | Reassign a page to a different author | page_id, new_author_id | None | id, title, status, date, modified, link | readonly=false, destructive=true, idempotent=true | edit_pages, edit_others_pages |
| `wp-mcp/update-page-slug` | Write | Change a page's URL slug | page_id, slug | None | id, title, status, date, modified, link | readonly=false, destructive=true, idempotent=true | edit_pages |
| `wp-mcp/update-page-attributes` | Write | Update a page's parent, menu order, and/or template | page_id | parent_id, menu_order, template | id, title, status, excerpt, author, date, modified, link | readonly=false, destructive=true, idempotent=true | edit_pages |

### 4i. Pages — Revisions

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/list-page-revisions` | Read | List saved revisions for a page | page_id | page, per_page | revisions[], total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | edit_pages |
| `wp-mcp/get-page-revision` | Read | Get a single revision's full content | page_id, revision_id | None | id, parent_id, author, title, content, excerpt, date, modified | readonly=true, destructive=false, idempotent=true | edit_pages |
| `wp-mcp/restore-page-revision` | Write | Restore a page to a previous revision | page_id, revision_id | None | id, title, status, date, modified, link | readonly=false, destructive=true, idempotent=false | edit_pages |

### 4j. Media

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/list-media` | Read | List visible media attachments | None | search, mime_type, page, per_page (max 50) | media[], pagination | readonly=true, destructive=false, idempotent=true | read |
| `wp-mcp/get-media` | Read | Get one attachment's metadata and dimensions | media_id | None | id, title, caption, description, alt_text, mime_type, url, parent_id, dimensions, sizes | readonly=true, destructive=false, idempotent=true | read + read_post |
| `wp-mcp/upload-media` | Write | Create attachment from client-provided base64 content | filename, content_base64 | mime_type, title, caption, description, alt_text, parent_id | complete media object | readonly=false, destructive=false, idempotent=false | upload_files |
| `wp-mcp/upload-media-from-url` | Write | Safely sideload a remote HTTP(S) file | url | filename, metadata, parent_id, allowed_hosts, deny_hosts | complete media object | readonly=false, destructive=false, idempotent=false | upload_files |
| `wp-mcp/update-media` | Write | Update title, caption, description, and/or alt text | media_id | title, caption, description, alt_text | complete media object | readonly=false, destructive=true, idempotent=true | edit_posts + edit_post |
| `wp-mcp/replace-media` | Write | Replace file while preserving attachment ID | media_id, filename, content_base64 | title, alt_text | complete media object | readonly=false, destructive=true, idempotent=false | edit_posts + upload_files + edit_post |
| `wp-mcp/attach-media` | Write | Attach media to an editable post or page | media_id, post_id | None | complete media object | readonly=false, destructive=true, idempotent=true | edit_post (media + parent) |
| `wp-mcp/detach-media` | Write | Detach media without deleting it | media_id | None | complete media object | readonly=false, destructive=true, idempotent=true | edit_post (media) + edit_post (parent) |
| `wp-mcp/set-featured-image` | Write | Set an image as featured media on a post or page | post_id, media_id | None | post_id, media_id, media_url | readonly=false, destructive=true, idempotent=true | edit_posts + edit_post |
| `wp-mcp/remove-featured-image` | Write | Remove featured media without deleting the attachment | post_id | None | post_id, removed_media_id, featured_media_id | readonly=false, destructive=true, idempotent=true | edit_posts + edit_post |
| `wp-mcp/regenerate-media-metadata` | Write | Regenerate metadata and image sub-sizes | media_id | None | complete media object | readonly=false, destructive=true, idempotent=true | edit_posts + edit_post |
| `wp-mcp/trash-media` | Write | Move media to WordPress trash | media_id | None | complete media object | readonly=false, destructive=true, idempotent=true | edit_posts + delete_post |
| `wp-mcp/restore-media` | Write | Restore media from WordPress trash | media_id | None | complete media object | readonly=false, destructive=false, idempotent=true | edit_posts + delete_post |
| `wp-mcp/delete-media-permanently` | Write | Permanently delete attachment and core-managed files | media_id | None | id, deleted | readonly=false, destructive=true, idempotent=false | delete_posts + delete_post |
| `wp-mcp/bulk-trash-media` | Write | Trash up to 20 attachments with per-item results | media_ids (max 20) | None | results[], trashed, failed | readonly=false, destructive=true, idempotent=true | edit_posts + delete_post per item |

### 4k. Taxonomies

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/list-categories` | Read | List core categories | None | None | terms[] | readonly=true, destructive=false, idempotent=true | read |
| `wp-mcp/list-tags` | Read | List core tags | None | None | terms[] | readonly=true, destructive=false, idempotent=true | read |
| `wp-mcp/list-taxonomies` | Read | Discover registered taxonomies and capabilities | None | object_type | taxonomies[] | readonly=true, destructive=false, idempotent=true | read |
| `wp-mcp/get-taxonomy` | Read | Get one registered taxonomy | taxonomy | None | registration and capabilities | readonly=true, destructive=false, idempotent=true | read |
| `wp-mcp/list-terms` | Read | List terms with search, hierarchy, counts, and pagination | taxonomy | search, parent, hide_empty, page, per_page | taxonomy, terms[], pagination | readonly=true, destructive=false, idempotent=true | read |
| `wp-mcp/get-term` | Read | Get a term and registered REST-visible metadata | taxonomy, term_id | None | term fields, meta | readonly=true, destructive=false, idempotent=true | read |
| `wp-mcp/create-term` | Write | Create a term in a registered taxonomy | taxonomy, name | slug, description, parent | term fields, meta | readonly=false, destructive=false, idempotent=false | taxonomy manage_terms |
| `wp-mcp/update-term` | Write | Update explicit term fields | taxonomy, term_id | name, slug, description, parent | term fields, meta | readonly=false, destructive=true, idempotent=true | taxonomy edit_terms |
| `wp-mcp/delete-term` | Write | Delete a term using WordPress rules | taxonomy, term_id | None | id, taxonomy, deleted | readonly=false, destructive=true, idempotent=false | taxonomy delete_terms |
| `wp-mcp/assign-terms` | Write | Add terms to an editable post/page/CPT | taxonomy, object_type, object_id, term_ids | None | relationship result | readonly=false, destructive=true, idempotent=true | taxonomy assign_terms + edit_post |
| `wp-mcp/remove-terms` | Write | Remove terms from an editable post/page/CPT | taxonomy, object_type, object_id, term_ids | None | relationship result | readonly=false, destructive=true, idempotent=true | taxonomy assign_terms + edit_post |
| `wp-mcp/bulk-assign-terms` | Write | Assign terms to up to 20 objects | taxonomy, object_type, object_ids, term_ids | None | results[], assigned, failed | readonly=false, destructive=true, idempotent=true | taxonomy assign_terms + edit_post |
| `wp-mcp/bulk-remove-terms` | Write | Remove terms from up to 20 objects | taxonomy, object_type, object_ids, term_ids | None | results[], removed, failed | readonly=false, destructive=true, idempotent=true | taxonomy assign_terms + edit_post |
| `wp-mcp/get-term-meta` | Read | Read one registered REST-visible term meta key | taxonomy, term_id, meta_key | None | taxonomy, term_id, meta_key, value | readonly=true, destructive=false, idempotent=true | read |
| `wp-mcp/update-term-meta` | Write | Update one registered term meta key | taxonomy, term_id, meta_key, value | None | taxonomy, term_id, meta_key, value | readonly=false, destructive=true, idempotent=true | edit_term_meta |
| `wp-mcp/delete-term-meta` | Write | Delete one registered term meta key | taxonomy, term_id, meta_key | None | taxonomy, term_id, meta_key, deleted | readonly=false, destructive=true, idempotent=true | edit_term_meta |

### 4l. Comments

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/list-comments` | Read | List readable comments with post, author, status, parent, date, and search filters | None | post_id, author_id, status, parent_id, search, date_after, date_before, page, per_page (max 50) | comments[], status, pagination | readonly=true, destructive=false, idempotent=true | read; moderation for non-public statuses |
| `wp-mcp/get-comment` | Read | Get one readable comment and public context; moderator-only email | comment_id | None | comment fields, optional author_email | readonly=true, destructive=false, idempotent=true | read + read_post |
| `wp-mcp/create-comment` | Write | Create a comment on a public or readable private post using the authenticated user's identity; WordPress decides approval | post_id, content | None | comment fields | readonly=false, destructive=false, idempotent=false | edit_posts + read_post |
| `wp-mcp/reply-comment` | Write | Create a threaded reply to an approved or held comment | parent_comment_id, content | None | comment fields | readonly=false, destructive=false, idempotent=false | edit_posts + read_post |
| `wp-mcp/update-comment` | Write | Update content through edit_comment; author fields are moderator-only | comment_id | content, author_name, author_url, author_email | comment fields, optional author_email | readonly=false, destructive=true, idempotent=true | edit_comment |
| `wp-mcp/approve-comment` | Write | Approve a comment | comment_id | None | comment fields | readonly=false, destructive=true, idempotent=true | moderate_comments |
| `wp-mcp/unapprove-comment` | Write | Move a comment to the moderation queue | comment_id | None | comment fields | readonly=false, destructive=true, idempotent=true | moderate_comments |
| `wp-mcp/mark-comment-spam` | Write | Mark a comment as spam | comment_id | None | comment fields | readonly=false, destructive=true, idempotent=true | moderate_comments |
| `wp-mcp/unspam-comment` | Write | Remove a comment from spam, restoring its previous status | comment_id | None | comment fields | readonly=false, destructive=true, idempotent=true | moderate_comments |
| `wp-mcp/trash-comment` | Write | Move a comment to the WordPress trash | comment_id | None | comment fields | readonly=false, destructive=true, idempotent=true | moderate_comments |
| `wp-mcp/restore-comment` | Write | Restore a trashed comment | comment_id | None | comment fields | readonly=false, destructive=false, idempotent=true | moderate_comments |
| `wp-mcp/delete-comment-permanently` | Write | Permanently delete one comment and promote its replies | comment_id | None | id, deleted | readonly=false, destructive=true, idempotent=false | moderate_comments |
| `wp-mcp/bulk-moderate-comments` | Write | Apply one fixed moderation action to up to 20 comments with per-item results; permanent deletion excluded | comment_ids (max 20), action | None | results[], processed, failed | readonly=false, destructive=true, idempotent=true | moderate_comments |

### 4m. Users, Roles, and Application Passwords

The users domain exposes 22 explicit operations. User profile reads never
include password hashes or authentication material. User deletion requires an
existing reassignment target, role changes use WordPress's editable-role and
meta-capability rules, and the plugin-managed `wp_mcp_agent` role is protected.
Application Passwords use `WP_Application_Passwords`: list/revoke responses
contain metadata only, while plaintext is returned only by the creation call.

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/list-users` | Read | List users visible to the authenticated administrator with bounded pagination | None | search, role, page, per_page | users[], total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | list_users |
| `wp-mcp/get-user` | Read | Get one user profile, subject to the native edit/list boundary; authentication fields are never returned | user_id | None | id, username, name, first_name, last_name, url, locale, description, roles, email | readonly=true, destructive=false, idempotent=true | read + edit_user (target) |
| `wp-mcp/get-current-user` | Read | Get the authenticated user profile without password or authentication material | None | None | id, username, name, first_name, last_name, url, locale, description, roles, email | readonly=true, destructive=false, idempotent=true | read |
| `wp-mcp/create-user` | Write | Create a user with explicit credentials; the supplied password is never returned or audited | username, email, password | role, display_name, first_name, last_name | id, username, name, roles, email | readonly=false, destructive=false, idempotent=false | create_users |
| `wp-mcp/update-user` | Write | Update only explicit profile fields: display name, email, URL, locale, first/last name, and description | user_id | display_name, email, url, locale, first_name, last_name, description | id, username, name, roles, email | readonly=false, destructive=true, idempotent=false | read + edit_user (target) |
| `wp-mcp/set-user-password` | Write | Set a user password explicitly; never returned, persisted in logs, or exposed by read abilities | user_id, password | None | id, updated | readonly=false, destructive=true, idempotent=false | read + edit_user (target) |
| `wp-mcp/delete-user` | Write | Delete one user only with an explicit existing reassignment target; implicit content deletion is refused | user_id, reassign_user_id | None | id, deleted, reassigned_to | readonly=false, destructive=true, idempotent=false | delete_users + delete_user (target) |
| `wp-mcp/change-user-role` | Write | Replace a user role only when the requested role is editable by WordPress and the native promote boundary allows it | user_id, role | None | id, username, name, roles | readonly=false, destructive=true, idempotent=true | promote_users + promote_user (target) |
| `wp-mcp/add-user-role` | Write | Add one explicit WordPress role subject to native promotion and editable-role checks | user_id, role | None | id, username, name, roles | readonly=false, destructive=true, idempotent=true | promote_users + promote_user (target) |
| `wp-mcp/remove-user-role` | Write | Remove one explicit WordPress role subject to native promotion and editable-role checks | user_id, role | None | id, username, name, roles | readonly=false, destructive=true, idempotent=true | promote_users + promote_user (target) |
| `wp-mcp/list-user-capabilities` | Read | List effective granted capabilities and roles without exposing user metadata or password material | user_id | None | user_id, roles, capabilities | readonly=true, destructive=false, idempotent=true | read + edit_user (target) |
| `wp-mcp/list-roles` | Read | List registered WordPress roles and granted capability names for administrators | None | None | roles[], total | readonly=true, destructive=false, idempotent=true | list_users |
| `wp-mcp/get-role` | Read | Get one registered WordPress role and its granted capabilities | slug | None | slug, name, capabilities | readonly=true, destructive=false, idempotent=true | list_users |
| `wp-mcp/create-role` | Write | Create a role with an explicit bounded list of capabilities; the plugin-managed WordPress MCP Agent role is protected | slug, name | capabilities (max 100) | slug, name, capabilities | readonly=false, destructive=false, idempotent=false | manage_options |
| `wp-mcp/update-role-capabilities` | Write | Apply explicit add/remove capability lists to a non-protected role | slug | add_capabilities, remove_capabilities (max 100 each) | slug, name, capabilities | readonly=false, destructive=true, idempotent=true | manage_options |
| `wp-mcp/add-role-capability` | Write | Add one explicit capability to a non-protected WordPress role | slug, capability | None | slug, name, capabilities | readonly=false, destructive=true, idempotent=true | manage_options |
| `wp-mcp/remove-role-capability` | Write | Remove one explicit capability from a non-protected WordPress role | slug, capability | None | slug, name, capabilities | readonly=false, destructive=true, idempotent=true | manage_options |
| `wp-mcp/delete-role` | Write | Delete an unused non-protected role; roles assigned to users are refused | slug | None | slug, deleted | readonly=false, destructive=true, idempotent=false | manage_options |
| `wp-mcp/list-application-passwords` | Read | List application-password metadata without password hashes or plaintext secrets | None | user_id | user_id, passwords[], total | readonly=true, destructive=false, idempotent=true | read + edit_user (target) |
| `wp-mcp/create-application-password` | Write | Create an application password using WordPress core; plaintext is returned once and never audited | name | user_id, app_id | user_id, password, metadata | readonly=false, destructive=true, idempotent=false | read + edit_user (target) |
| `wp-mcp/revoke-application-password` | Write | Revoke one application password by UUID without exposing its secret | uuid | user_id | user_id, uuid, revoked | readonly=false, destructive=true, idempotent=true | read + edit_user (target) |
| `wp-mcp/revoke-all-application-passwords` | Write | Revoke every application password for one user and return only the count | None | user_id | user_id, revoked | readonly=false, destructive=true, idempotent=true | read + edit_user (target) |

Source of truth: `WP_MCP_Ability_Matrix::get()`. "Capability" is a
general WordPress capability checked in the ability's
`permission_callback`; "Meta-Capability" is a per-object capability
(resolved by WordPress core's `map_meta_cap`) checked inside the
callback against the specific object being touched. Every ability
belongs to one of the 15 `wp-mcp-*` categories listed, with their
counts, in section 6.

### Posts

| Ability | Capability | Meta-Capability | Destructive | Idempotent |
|---|---|---|---|---|
| `list-posts` | read | — | No | Yes |
| `get-post` | — | read_post | No | Yes |
| `create-post` | edit_posts | — | No | No |
| `update-post` | — | edit_post | Yes | No |
| `publish-post` | edit_posts, publish_posts | edit_post | Yes | Yes |
| `unpublish-post` | edit_posts | edit_post | Yes | Yes |
| `schedule-post` | edit_posts, publish_posts | edit_post | Yes | Yes |
| `change-post-status` | edit_posts | edit_post | Yes | Yes |
| `trash-post` | edit_posts | delete_post | Yes | Yes |
| `restore-post` | edit_posts | delete_post | No | Yes |
| `delete-post-permanently` | delete_posts | delete_post | Yes | No |
| `change-post-author` | edit_posts, edit_others_posts | edit_post | Yes | Yes |
| `update-post-slug` | edit_posts | edit_post | Yes | Yes |
| `stick-post` | edit_posts, edit_others_posts | edit_post | No | Yes |
| `unstick-post` | edit_posts, edit_others_posts | edit_post | No | Yes |
| `set-post-password` | edit_posts | edit_post | Yes | Yes |
| `list-post-revisions` | edit_posts | edit_post | No | Yes |
| `get-post-revision` | edit_posts | edit_post | No | Yes |
| `restore-post-revision` | edit_posts | edit_post | Yes | No |
| `get-post-autosave` | edit_posts | edit_post | No | Yes |
| `duplicate-post` | edit_posts | read_post (source) | No | No |
| `bulk-trash-posts` | edit_posts | delete_post (per item) | Yes | Yes |

### Pages

| Ability | Capability | Meta-Capability | Destructive | Idempotent |
|---|---|---|---|---|
| `list-pages` | read | — | No | Yes |
| `get-page` | — | read_post | No | Yes |
| `create-page` | edit_posts | — | No | No |
| `update-page` | — | edit_post | Yes | No |
| `publish-page` | edit_pages, publish_pages | edit_post | Yes | Yes |
| `unpublish-page` | edit_pages | edit_post | Yes | Yes |
| `schedule-page` | edit_pages, publish_pages | edit_post | Yes | Yes |
| `trash-page` | edit_pages | delete_post | Yes | Yes |
| `restore-page` | edit_pages | delete_post | No | Yes |
| `delete-page-permanently` | delete_pages | delete_post | Yes | No |
| `change-page-author` | edit_pages, edit_others_pages | edit_post | Yes | Yes |
| `update-page-slug` | edit_pages | edit_post | Yes | Yes |
| `update-page-attributes` | edit_pages | edit_post | Yes | Yes |
| `list-page-revisions` | edit_pages | edit_post | No | Yes |
| `get-page-revision` | edit_pages | edit_post | No | Yes |
| `restore-page-revision` | edit_pages | edit_post | Yes | No |

### Media

| Ability | Capability | Meta-Capability | Destructive | Idempotent |
|---|---|---|---|---|
| `list-media` | read | — | No | Yes |
| `get-media` | read | read_post (attachment) | No | Yes |
| `upload-media` | upload_files | — | No | No |
| `upload-media-from-url` | upload_files | — | No | No |
| `update-media` | edit_posts | edit_post (attachment) | Yes | Yes |
| `replace-media` | edit_posts, upload_files | edit_post (attachment) | Yes | No |
| `attach-media` | edit_posts | edit_post (media + parent) | Yes | Yes |
| `detach-media` | edit_posts | edit_post (attachment), edit_post (parent) | Yes | Yes |
| `set-featured-image` | edit_posts | edit_post (post/page) | Yes | Yes |
| `remove-featured-image` | edit_posts | edit_post (post/page) | Yes | Yes |
| `regenerate-media-metadata` | edit_posts | edit_post (attachment) | Yes | Yes |
| `trash-media` | edit_posts | delete_post (attachment) | Yes | Yes |
| `restore-media` | edit_posts | delete_post (attachment) | No | Yes |
| `delete-media-permanently` | delete_posts | delete_post (attachment) | Yes | No |
| `bulk-trash-media` | edit_posts | delete_post (per item) | Yes | Yes |

### Taxonomies

| Ability | Capability | Meta-Capability | Destructive | Idempotent |
|---|---|---|---|---|
| `list-categories` | read | — | No | Yes |
| `list-tags` | read | — | No | Yes |
| `list-taxonomies` | read | — | No | Yes |
| `get-taxonomy` | read | — | No | Yes |
| `list-terms` | read | — | No | Yes |
| `get-term` | read | — | No | Yes |
| `create-term` | manage_terms (taxonomy) | — | No | No |
| `update-term` | edit_terms (taxonomy) | — | Yes | Yes |
| `delete-term` | delete_terms (taxonomy) | — | Yes | No |
| `assign-terms` | assign_terms (taxonomy) | edit_post (object) | Yes | Yes |
| `remove-terms` | assign_terms (taxonomy) | edit_post (object) | Yes | Yes |
| `bulk-assign-terms` | assign_terms (taxonomy) | edit_post (per object) | Yes | Yes |
| `bulk-remove-terms` | assign_terms (taxonomy) | edit_post (per object) | Yes | Yes |
| `get-term-meta` | read | — | No | Yes |
| `update-term-meta` | edit_terms (taxonomy) | edit_term_meta | Yes | Yes |
| `delete-term-meta` | edit_terms (taxonomy) | edit_term_meta | Yes | Yes |

### Comments

| Ability | Capability | Meta-Capability | Destructive | Idempotent |
|---|---|---|---|---|
| `list-comments` | read | read_post (comment post) | No | Yes |
| `get-comment` | read | read_post (comment post) | No | Yes |
| `create-comment` | edit_posts | read_post (comment post) | No | No |
| `reply-comment` | edit_posts | read_post (comment post) | No | No |
| `update-comment` | edit_posts | edit_comment | Yes | Yes |
| `approve-comment` | moderate_comments | edit_comment | Yes | Yes |
| `unapprove-comment` | moderate_comments | edit_comment | Yes | Yes |
| `mark-comment-spam` | moderate_comments | edit_comment | Yes | Yes |
| `unspam-comment` | moderate_comments | edit_comment | Yes | Yes |
| `trash-comment` | moderate_comments | edit_comment | Yes | Yes |
| `restore-comment` | moderate_comments | edit_comment | No | Yes |
| `delete-comment-permanently` | moderate_comments | edit_comment | Yes | No |
| `bulk-moderate-comments` | moderate_comments | edit_comment (per item) | Yes | Yes |

## 6. MCP Categories

All 15 categories are pre-registered (via
`WP_MCP_Ability_Categories`) so future domains can add abilities to
them without touching category registration. The `wp-mcp-extensibility`
count below is the documented surface: 2 abilities are always registered and
the other 15 only when their third-party plugin is present.

| Category | Status |
|---|---|
| `wp-mcp-content` | Active (58 abilities) |
| `wp-mcp-media` | Active (15 abilities) |
| `wp-mcp-taxonomies` | Active (16 abilities) |
| `wp-mcp-comments` | Active (13 abilities) |
| `wp-mcp-users` | Active (22 abilities) |
| `wp-mcp-navigation` | Active (11 abilities) |
| `wp-mcp-site-editor` | Active (22 abilities) |
| `wp-mcp-plugins` | Active (11 abilities) |
| `wp-mcp-themes` | Active (10 abilities) |
| `wp-mcp-settings` | Active (18 abilities) |
| `wp-mcp-system` | Active (25 abilities) |
| `wp-mcp-privacy` | Active (8 abilities) |
| `wp-mcp-network` | Active (30 abilities) |
| `wp-mcp-extensibility` | Active (17 abilities) |
| `wp-mcp-discovery` | Active (9 abilities) |

## 7. Permissions & Security Model
- **Role:** `wp_mcp_agent` with capabilities: `read`, `edit_posts`, `edit_published_posts`, `publish_posts`. Unchanged since v0.1 — deliberately not expanded for #3's trash/delete/author/sticky abilities (see below).
- Mutating abilities are authorized exclusively via WordPress's native meta-capabilities (`edit_post`, `delete_post`, `read_post`), resolved by core's `map_meta_cap` — there is no hardcoded `post_author === current user` gate. A user without `edit_others_posts`/`delete_posts` (like the default `wp_mcp_agent` role) is automatically restricted by WordPress core; a user with an elevated capability (Editor, Administrator) may operate on others' content, delete content, or reassign authorship exactly as WordPress core allows.
- **The default `wp_mcp_agent` role has neither `delete_posts` nor `edit_others_posts`.** This means trash/restore/delete-permanently/change-author/stick/unstick always return `wp_mcp_permission_denied` or `wp_mcp_ownership_violation` for that role, even on its own content — this is intentional: the ability catalog can be broad while actual execution stays limited by whatever capabilities the authenticated user's real role provides. A site admin who wants an agent to trash or reassign content must grant the relevant capability (or use a different WordPress role) — the plugin does not grant it by default.
- `change-post-author`/`change-page-author` always require `edit_others_posts` regardless of the target author (mirrors WP-admin), and validate that the target user exists and can author content.
- `stick-post`/`unstick-post` require `edit_others_posts` because they affect what every site visitor sees, not just the object being modified.
- `permission_callback` provides a first-pass general-capability check; `execute_callback` re-verifies via the meta-capability against the specific object
- All inputs sanitized: `sanitize_text_field` for titles, `wp_kses_post` for content/excerpt — except the Site Editor domain (§4o), whose block markup is deliberately validated structurally rather than kses'd; see the note at the end of §4o
- IDs validated as positive integers via `absint()`
- Taxonomy names, object types, term IDs, and parent IDs are always validated against the live WordPress registry; IDs are never interpreted across taxonomies
- Term CRUD uses the taxonomy's native `manage_terms`, `edit_terms`, and `delete_terms` capabilities; assignment uses `assign_terms` plus the object's native `edit_post` meta-capability
- Term metadata is limited to keys registered for the requested taxonomy (or globally) and explicitly exposed through `show_in_rest`; values are validated against the registered schema and auth callback
- Bulk taxonomy assignment/removal is capped at 20 objects and reports each object independently
- Comment reads expose public author data only; author email is returned only in moderator-level detail responses, and comment IP/agent fields are never returned
- Comment creation takes identity from the authenticated WordPress user and never accepts client-provided author, approval, spam, trash, or IP fields
- Comment moderation and lifecycle abilities require native `moderate_comments`; comment editing requires native `edit_comment`; permanent deletion is the explicit single-item operation guarded by native `moderate_comments`
- `bulk-moderate-comments` is capped at 20 comments, supports only the fixed actions approve/unapprove/spam/unspam/trash/restore, reports every item independently, and never permanently deletes
- User profile updates are allowlisted; login names, roles, password hashes, cookies, nonces, and arbitrary user meta are never accepted or returned by profile abilities
- User creation requires explicit username, email, and password input; the password is used only by WordPress core and is never returned or audited
- User deletion requires an explicit existing reassignment user and is not exposed for multisite; implicit deletion of authored content is refused
- Role changes require native `promote_users`/`promote_user` checks and the filtered `get_editable_roles()` list; self-demotion that removes promotion is refused
- Role CRUD requires `manage_options` plus the relevant native user-management capability, refuses deletion of roles assigned to users, and protects `wp_mcp_agent`
- Application Passwords use WordPress core availability checks and APIs; list/revoke output excludes the persisted hash, and plaintext is returned only by create
- `update-page-attributes` rejects an unregistered template and a parent that would create a circular page hierarchy
- `bulk-trash-posts` and `bulk-trash-media` are capped at 20 items per request and never abort the batch for one item's failure — each item is validated, executed, and audited independently
- Uploads accept only client-provided base64 content or a remote HTTP(S) URL; WordPress validates the actual MIME/extension, decoded content is capped at 10 MiB, and no server path is accepted or returned
- Remote uploads use `wp_safe_remote_get`, reject credentials/fragments, block private/reserved IPs and localhost, limit redirects/timeouts/response size, and support explicit `allowed_hosts` / `deny_hosts` policies
- Media metadata changes are allowlisted; arbitrary post meta, attachment paths, options, SQL, and filesystem browsing are not exposed
- No `unfiltered_html`, no SQL, no generic filesystem access
- No arbitrary PHP/SQL execution, no arbitrary options read/write, no generic filesystem access — and no indirect wrapper (generic function/callback executor, generic REST route executor) that would provide equivalent capability, now or in future ability domains
- MCP-only exposure via `meta.mcp.public=true` (not `meta.public`)
- `show_in_rest=false` — no additional REST endpoints
- Plugins/themes/core updates (Issue #9): authorization is WordPress's native primitive plugin/theme/core capability alone — there is no per-object meta-capability for these operations in WordPress, so `install_plugins`/`update_plugins`/`delete_plugins`/`activate_plugins`, the equivalent theme capabilities, and `update_core` are the sole gate, exactly as WP-admin itself enforces (`update_core` is Super Admin-only by default on multisite, with no additional branching needed here)
- Install, update, and delete are always separate abilities for plugins and themes; a remote install never activates a plugin or switches to a theme
- `install-plugin-from-url` / `install-theme-from-url` reuse the same SSRF/host-policy validation as `upload-media-from-url` (`WP_MCP_Permissions::validate_remote_url()`), and every download in this domain is capped at 50 MB via a temporary `http_request_args` filter
- `delete-plugin` / `delete-theme` refuse to delete an active plugin or the active theme; deactivate or switch away first
- Plugin/theme updates rely on WordPress core's own automatic backup-and-restore-on-failure for single-item upgrades (available since WP 6.3); `update-core` has no equivalent core mechanism for a manual, synchronous update and does not implement its own rollback — documented on the ability rather than papered over
- Audit entries for install/update abilities may include `slug`, `from_version`, and `to_version` when known — still no secrets, tokens, or full download URLs
- Custom post types (Issue #11): a post type name is a validated selector resolved through `get_post_type_object()`, never a dispatcher; the ability set is fixed at 20 and does not grow with the site's registered types
- No literal `edit_posts` / `publish_posts` / `delete_posts` string is used to gate a custom post type. Creation checks the type's own `cap->create_posts` (which a type may declare separately from `edit_posts`), publishing checks `cap->publish_posts`, and per-object access uses the native `read_post`/`edit_post`/`delete_post` meta-capabilities that core's `map_meta_cap()` resolves through that same type object — including types registered with `map_meta_cap => false`
- `post`, `page`, `attachment` and every other core built-in type are refused by the custom post type abilities: each already has its own explicit domain with type-specific rules, and a second generic path would be a weaker duplicate of them. Discovery still reports them with a machine-readable reason
- A post type that never opted into `show_in_rest` is discoverable but not operable — the same explicit-opt-in rule the registered-metadata surface uses
- Post type `supports` is enforced: `title`/`content`/`excerpt` writes, revisions and featured media are refused on a type that does not declare the corresponding support
- Custom post type creation always produces a draft owned by the current user; `post_status`, `post_author`, `post_type` semantics and slug are never taken from client input beyond the validated type selector
- Registered post metadata is limited to keys registered for the requested subtype (or globally), exposed through `show_in_rest`, stored as `single => true`, and not protected. `_`-prefixed and `is_protected_meta()` keys are refused before the registry is consulted; values are validated and sanitized against the key's own registered schema; writes and deletions additionally check the native per-object `edit_post_meta` / `delete_post_meta` capabilities, so a key's own `auth_callback` still has the final word
- There is no generic metadata accessor: this domain only ever calls the `*_post_meta()` family — user, term, option, site and network metadata are out of scope

## 8. Installation
1. Ensure WordPress >= 6.9 and PHP >= 7.4
2. Install and activate mcp-adapter plugin
3. Download `wordpress-mcp-abilities-<version>.zip` (and its `.sha256` file) from the
   [Releases page](https://github.com/ToniLopezPhoto/wordpress_mcp_abilities/releases),
   verify the checksum, then upload the zip via Plugins > Add New > Upload
4. Activate the plugin
5. The `wp_mcp_agent` role is created automatically on activation

## 9. Creating the WordPress MCP Agent User
1. Go to Users > Add New
2. Set username (e.g. `wp-mcp-agent`)
3. Set email
4. Set role to "WordPress MCP Agent"
5. Save

## 10. Generating an Application Password
1. Go to Users > edit the agent user
2. Scroll to Application Passwords section
3. Enter a name (e.g. "MCP Agent")
4. Click "Add New Application Password"
5. Copy the generated password (shown only once)

## 11. MCP Endpoint
```http
POST https://your-site.com/wp-json/mcp/mcp-adapter-default-server
```
**Authentication:** HTTP Basic with WordPress username + Application Password

The abilities are discovered via the default server's meta-tools:
- `mcp-adapter-discover-abilities` → lists all wp-mcp/* abilities
- `mcp-adapter-get-ability-info` → get schema for specific ability
- `mcp-adapter-execute-ability` → execute an ability

## 12. Configuration for MCP Clients
Example for Claude Desktop (`claude_desktop_config.json`) using the
[`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote)
proxy, which bridges a local MCP client to the adapter endpoint. Any MCP client
that can send HTTP Basic credentials to the endpoint works as well; check the
client's own documentation for its configuration format.
```json
{
  "mcpServers": {
    "wordpress": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote"],
      "env": {
        "WP_API_URL": "https://your-site.com/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "wp-mcp-agent",
        "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

### 4n. Navigation — Classic Menus (Issue #8)

11 abilities, all gated by `edit_theme_options`, wrapping the native
`wp_get_nav_menus`, `wp_update_nav_menu_object`, `wp_update_nav_menu_item`
APIs and the theme's registered location theme-mods. Mutations reject
dangerous URL schemes (e.g. `javascript:`) and validate that an item belongs
to the menu it claims to belong to.

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/list-nav-menus` | Read | List classic navigation menus, registered locations, and bounded public menu-item fields | None | None | menus[], total | readonly=true, destructive=false, idempotent=true | edit_theme_options |
| `wp-mcp/get-nav-menu` | Read | Get one classic navigation menu and its items | menu_id | None | id, name, slug, description, count, locations, auto_add, items[] | readonly=true, destructive=false, idempotent=true | edit_theme_options |
| `wp-mcp/list-menu-locations` | Read | List navigation locations registered by the active theme and their assignments | None | None | locations[], total | readonly=true, destructive=false, idempotent=true | edit_theme_options |
| `wp-mcp/create-nav-menu` | Write | Create a classic navigation menu with an explicit name and optional description | name | description | id, name, slug, description, count, locations, auto_add, items[] | readonly=false, destructive=false, idempotent=false | edit_theme_options |
| `wp-mcp/update-nav-menu` | Write | Update the explicit name or description of a classic navigation menu | menu_id | name, description | id, name, slug, description, count, locations, auto_add, items[] | readonly=false, destructive=true, idempotent=true | edit_theme_options |
| `wp-mcp/delete-nav-menu` | Write | Permanently delete one classic navigation menu and its menu items | menu_id | None | id, deleted | readonly=false, destructive=true, idempotent=false | edit_theme_options |
| `wp-mcp/assign-menu-location` | Write | Assign or unassign a classic menu from one location registered by the active theme | menu_id, location | assign | menu_id, location, assigned | readonly=false, destructive=true, idempotent=true | edit_theme_options |
| `wp-mcp/add-menu-item` | Write | Add a custom, post, page, or taxonomy item to a classic navigation menu | menu_id, title, type | url, object_id, taxonomy, parent_id, position, target | id, title, url, type, object, object_id, parent_id, position, target, classes, xfn, description | readonly=false, destructive=false, idempotent=false | edit_theme_options |
| `wp-mcp/update-menu-item` | Write | Update the explicit title, URL, hierarchy, or position of one item belonging to a classic menu | menu_id, item_id | title, url, parent_id, position | id, title, url, type, object, object_id, parent_id, position, target, classes, xfn, description | readonly=false, destructive=true, idempotent=true | edit_theme_options |
| `wp-mcp/remove-menu-item` | Write | Permanently remove one item belonging to a classic navigation menu | menu_id, item_id | None | id, deleted | readonly=false, destructive=true, idempotent=false | edit_theme_options |
| `wp-mcp/reorder-menu-items` | Write | Persist an explicit complete ordering for every item in one classic navigation menu | menu_id, item_ids | None | menu_id, item_ids[] | readonly=false, destructive=true, idempotent=true | edit_theme_options |

### 4o. Site Editor — Templates, Patterns, Navigation Blocks, and Styles (Issue #8)

22 abilities, all gated by `edit_theme_options`, operating exclusively
through native WordPress entities and Site Editor APIs — theme files are
never read or written. A theme-sourced template is customized by creating
its database entity; deleting a customization never touches the original
theme file. `list-templates`, `list-template-parts`, `list-patterns`, and
`list-navigation-blocks` are genuinely unbounded (a theme or plugin can
register arbitrarily many) and use the shared `page`/`per_page` pagination
contract (`WP_MCP_Ability_Schema::paginate()`, 1–50 items per page,
default 10 — the same helper used by `list-posts` and `list-media`);
`list-widget-areas` is bounded by the active theme's registered sidebars
and intentionally not paginated.

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/list-templates` | Read | List active-theme and database template entities through the WordPress block-template API | None | page, per_page | templates[], total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | edit_theme_options |
| `wp-mcp/get-template` | Read | Get one template without exposing theme files directly | template_id | None | id, slug, theme, type, source, origin, title, description, status, wp_id, has_theme_file, content, modified | readonly=true, destructive=false, idempotent=true | edit_theme_options |
| `wp-mcp/update-template` | Write | Create or update only the database representation of a template; theme files are never edited | template_id, content | title, description | id, slug, theme, type, source, origin, title, description, status, wp_id, has_theme_file, content, modified | readonly=false, destructive=true, idempotent=true | edit_theme_options |
| `wp-mcp/delete-template` | Write | Delete a database customization without touching the active theme file | template_id | None | id, deleted | readonly=false, destructive=true, idempotent=false | edit_theme_options |
| `wp-mcp/list-template-parts` | Read | List active-theme and database template part entities through the WordPress block-template API | None | page, per_page | templates[], total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | edit_theme_options |
| `wp-mcp/get-template-part` | Read | Get one template part without exposing theme files directly | template_id | None | id, slug, theme, type, source, origin, title, description, status, wp_id, has_theme_file, content, modified, area | readonly=true, destructive=false, idempotent=true | edit_theme_options |
| `wp-mcp/update-template-part` | Write | Create or update only the database representation of a template part; theme files are never edited | template_id, content | title, description | id, slug, theme, type, source, origin, title, description, status, wp_id, has_theme_file, content, modified, area | readonly=false, destructive=true, idempotent=true | edit_theme_options |
| `wp-mcp/delete-template-part` | Write | Delete a database customization without touching the active theme file | template_id | None | id, deleted | readonly=false, destructive=true, idempotent=false | edit_theme_options |
| `wp-mcp/list-patterns` | Read | List registered patterns and database-backed synced patterns | None | page, per_page | patterns[], total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | edit_theme_options |
| `wp-mcp/get-pattern` | Read | Get one registered pattern by name or one synced database pattern by ID | pattern_id | None | id, source, title, description, content, status, sync_status, modified | readonly=true, destructive=false, idempotent=true | edit_theme_options |
| `wp-mcp/create-synced-pattern` | Write | Create a database-backed synced block pattern using the wp_block entity | title, content | None | id, source, title, description, content, status, sync_status, modified | readonly=false, destructive=false, idempotent=false | edit_theme_options + create_posts (wp_block) |
| `wp-mcp/update-synced-pattern` | Write | Update explicit fields on a database-backed synced block pattern | pattern_id | title, content, sync_status | id, source, title, description, content, status, sync_status, modified | readonly=false, destructive=true, idempotent=true | edit_theme_options + edit_post (wp_block) |
| `wp-mcp/delete-synced-pattern` | Write | Permanently delete one database-backed synced block pattern | pattern_id | None | id, deleted | readonly=false, destructive=true, idempotent=false | edit_theme_options + delete_post (wp_block) |
| `wp-mcp/list-navigation-blocks` | Read | List persisted wp_navigation entities used by block themes | None | page, per_page | navigations[], total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | edit_theme_options |
| `wp-mcp/get-navigation-block` | Read | Get one persisted wp_navigation entity | navigation_id | None | id, title, content, status, author, modified | readonly=true, destructive=false, idempotent=true | edit_theme_options |
| `wp-mcp/create-navigation-block` | Write | Create a persisted wp_navigation entity for block-theme navigation | title, content | None | id, title, content, status, author, modified | readonly=false, destructive=false, idempotent=false | edit_theme_options + create_posts (wp_navigation) |
| `wp-mcp/update-navigation-block` | Write | Update the title or block content of a persisted wp_navigation entity | navigation_id | title, content | id, title, content, status, author, modified | readonly=false, destructive=true, idempotent=true | edit_theme_options + edit_post (wp_navigation) |
| `wp-mcp/delete-navigation-block` | Write | Permanently delete one persisted wp_navigation entity | navigation_id | None | id, deleted | readonly=false, destructive=true, idempotent=false | edit_theme_options + delete_post (wp_navigation) |
| `wp-mcp/get-theme-context` | Read | Report active-theme support and registered locations without reading theme files | None | None | name, stylesheet, is_block_theme, template_editing, menu_locations[], global_styles_available | readonly=true, destructive=false, idempotent=true | edit_theme_options |
| `wp-mcp/get-global-styles` | Read | Read persisted user global styles, split into settings/styles branches, when the active installation exposes the core API | None | None | id, theme, settings, styles | readonly=true, destructive=false, idempotent=true | edit_theme_options |
| `wp-mcp/update-global-styles` | Write | Update only explicit settings or styles branches through the core global-styles entity | None | settings, styles | id, theme, settings, styles | readonly=false, destructive=true, idempotent=true | edit_theme_options |
| `wp-mcp/list-widget-areas` | Read | List registered widget areas; widget CRUD is withheld because classic widget options have no stable core entity API | None | None | areas[], total, write_support | readonly=true, destructive=false, idempotent=true | edit_theme_options |

Block content accepted by `update-template`, `update-template-part`,
`create-synced-pattern`, `update-synced-pattern`, `create-navigation-block`,
and `update-navigation-block` is validated structurally (non-empty, UTF-8,
size-bounded, at least one real block) but **never sanitized with
`wp_kses_post()`** — the `edit_theme_options` capability already implies
`unfiltered_html` for this caller, matching core's own Site Editor REST
controllers, and kses's block-comment stripping would corrupt legitimate
block attributes containing `--` or `&`. See `WP_MCP_Site_Editor::validate_block_content()`.

### 4p. Plugins (Issue #9)

11 abilities, gated on WordPress's native primitive (non-object) plugin
capabilities — `activate_plugins`, `install_plugins`, `update_plugins`,
`delete_plugins` — the sole authorization gate, since WordPress has no
per-plugin meta-capability. Install, update, and delete are always
separate abilities; installing a plugin never activates it. Update relies
on WordPress core's own automatic temp-backup-and-restore-on-failure
mechanism for single-plugin upgrades (available since WP 6.3); this plugin
does not implement its own rollback. `delete-plugin` refuses to delete an
active plugin — deactivate it first. `install-plugin-from-url` reuses the
same SSRF/host-policy validation as `upload-media-from-url`
(`WP_MCP_Permissions::validate_remote_url()`), and every download made by
this domain (repository or URL) is capped at 50 MB via a shared
`http_request_args` filter (`WP_MCP_Permissions::with_download_limit()`).

**Network-scoped operations.** `activate_plugins` on its own is a per-site
capability: on multisite a site administrator can hold it (the network's
`menu_items['plugins']` setting grants it) with no authority over the
network at all. Every `network_wide` operation therefore additionally
requires `manage_network_plugins`, and so does changing auto-updates,
because `auto_update_plugins` is a network option whose single value
governs every site.

**Bulk updates.** `update-plugins` takes an explicit list (up to 20) and
validates every entry against the installed set before the upgrader is
constructed. `update-all-plugins` is a deliberately separate ability
capped at 50 items: "update everything" has an unbounded blast radius and
must never be what happens when a caller forgets a parameter. Both audit
one event per item, with that item's `from_version`/`to_version`.

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/list-plugins` | Read | List installed plugins | None | search, page, per_page | plugins[], total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | activate_plugins |
| `wp-mcp/get-plugin` | Read | Get one installed plugin | plugin_file | None | file, name, version, description, author, plugin_uri, requires_wp, requires_php, active, network_active, update_available | readonly=true, destructive=false, idempotent=true | activate_plugins |
| `wp-mcp/activate-plugin` | Write | Activate an installed plugin; a no-op on an already-active plugin | plugin_file | network_wide | (plugin fields, as above) | readonly=false, destructive=false, idempotent=true | activate_plugins |
| `wp-mcp/deactivate-plugin` | Write | Deactivate an installed plugin; a no-op on an already-inactive plugin | plugin_file | network_wide | (plugin fields, as above) | readonly=false, destructive=false, idempotent=true | activate_plugins |
| `wp-mcp/install-plugin-from-repo` | Write | Install a plugin by slug from the official WordPress.org repository; never activates it | slug | None | (plugin fields, as above) | readonly=false, destructive=false, idempotent=false | install_plugins |
| `wp-mcp/install-plugin-from-url` | Write | Install a plugin from an explicit HTTP(S) zip URL, subject to SSRF/host policy; never activates it | url | allowed_hosts, deny_hosts | (plugin fields, as above) | readonly=false, destructive=false, idempotent=false | install_plugins |
| `wp-mcp/update-plugin` | Write | Update an installed plugin to the latest available version | plugin_file | None | (plugin fields, as above) | readonly=false, destructive=true, idempotent=false | update_plugins |
| `wp-mcp/update-plugins` | Write | Update an explicit list of installed plugins (max 20) in one call | plugin_files | None | results[] (file, from_version, to_version, updated, error), requested, updated | readonly=false, destructive=true, idempotent=false | update_plugins |
| `wp-mcp/update-all-plugins` | Write | Update every installed plugin with an update available (max 50) | None | None | results[] (as above), requested, updated | readonly=false, destructive=true, idempotent=false | update_plugins |
| `wp-mcp/set-plugin-auto-update` | Write | Opt one plugin in or out of WordPress auto-updates; a no-op when already in that state | plugin_file, enabled | None | file, auto_update | readonly=false, destructive=true, idempotent=true | update_plugins (+ manage_network_plugins on multisite) |
| `wp-mcp/delete-plugin` | Write | Permanently delete an installed plugin's files; refuses an active plugin | plugin_file | None | file, deleted | readonly=false, destructive=true, idempotent=false | delete_plugins |

### 4q. Themes (Issue #9)

10 abilities, mirroring the Plugins domain: `switch_themes`,
`install_themes`, `update_themes`, `delete_themes` are the sole
authorization gate (no per-theme meta-capability exists). Install, update,
and delete are always separate abilities; installing a theme never
switches to it. `delete-theme` refuses to delete the currently active
theme (or the parent of an active child theme), and reports a failure
whenever core's `delete_theme()` returns any falsy value — filesystem
credentials being unavailable is not a successful delete. Update relies on
the same WP 6.3+ automatic backup-and-restore mechanism as plugin updates.
`install-theme-from-url` reuses the same SSRF/host-policy validation and
50 MB download cap as the Plugins domain, and bulk/auto-update behavior
mirrors it exactly — including requiring `manage_network_themes` on
multisite, since `auto_update_themes` is a network option.

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/list-themes` | Read | List installed themes | None | search, page, per_page | themes[], total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | switch_themes |
| `wp-mcp/get-theme` | Read | Get one installed theme | stylesheet | None | stylesheet, name, version, description, author, theme_uri, requires_wp, requires_php, parent_theme, active, update_available | readonly=true, destructive=false, idempotent=true | switch_themes |
| `wp-mcp/switch-theme` | Write | Activate an installed theme; a no-op on the already-active theme | stylesheet | None | (theme fields, as above) | readonly=false, destructive=false, idempotent=true | switch_themes |
| `wp-mcp/install-theme-from-repo` | Write | Install a theme by slug from the official WordPress.org repository; never switches to it | slug | None | (theme fields, as above) | readonly=false, destructive=false, idempotent=false | install_themes |
| `wp-mcp/install-theme-from-url` | Write | Install a theme from an explicit HTTP(S) zip URL, subject to SSRF/host policy; never switches to it | url | allowed_hosts, deny_hosts | (theme fields, as above) | readonly=false, destructive=false, idempotent=false | install_themes |
| `wp-mcp/update-theme` | Write | Update an installed theme to the latest available version | stylesheet | None | (theme fields, as above) | readonly=false, destructive=true, idempotent=false | update_themes |
| `wp-mcp/update-themes` | Write | Update an explicit list of installed themes (max 20) in one call | stylesheets | None | results[] (stylesheet, from_version, to_version, updated, error), requested, updated | readonly=false, destructive=true, idempotent=false | update_themes |
| `wp-mcp/update-all-themes` | Write | Update every installed theme with an update available (max 50) | None | None | results[] (as above), requested, updated | readonly=false, destructive=true, idempotent=false | update_themes |
| `wp-mcp/set-theme-auto-update` | Write | Opt one theme in or out of WordPress auto-updates; a no-op when already in that state | stylesheet, enabled | None | stylesheet, auto_update | readonly=false, destructive=true, idempotent=true | update_themes (+ manage_network_themes on multisite) |
| `wp-mcp/delete-theme` | Write | Permanently delete an installed theme's files; refuses the active theme | stylesheet | None | stylesheet, deleted | readonly=false, destructive=true, idempotent=false | delete_themes |

### 4r. System — Core, Translation, and Cross-Domain Updates (Issue #9)

4 abilities. Core updates are gated on the native `update_core`
capability, which WordPress itself restricts to Super Admins on multisite
(it is not part of the default multisite Administrator capability set) —
no additional multisite branching is implemented. Unlike plugin/theme
updates, WordPress has no automatic backup-and-restore mechanism for a
manual, synchronous core update (that mechanism, `WP_Automatic_Updater`,
applies only to the background auto-update path); `update-core` does not
implement its own rollback, and this is documented on the ability rather
than papered over.

`update-translations` is gated on `update_languages` — the same
capability the wp-admin Updates screen uses — and takes **no source
parameters at all**: the language pack list comes from WordPress's own
update data, never from the caller.

`list-available-updates` mirrors the wp-admin Updates screen in one read:
pending core, plugin, theme and translation updates, each item's new
version, and whether this installation satisfies its declared
`requires_wp`/`requires_php` (computed with core's own
`is_wp_version_compatible()`/`is_php_version_compatible()`). It reads the
existing update transients and never forces a fresh WordPress.org check.
A section is filled only when the caller holds the matching capability;
the `can_view_*` flags say why a section is empty, so an agent can tell
"nothing to update" apart from "not allowed to look". Each section is
capped at 100 items, with a matching `*_truncated` flag.

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/get-core-update-status` | Read | Report the current WordPress version and whether a core update is available, without forcing a fresh version check | None | None | current_version, latest_version, update_available, locale | readonly=true, destructive=false, idempotent=true | update_core |
| `wp-mcp/update-core` | Write | Update WordPress core to the preferred available version | None | None | from_version, to_version, updated | readonly=false, destructive=true, idempotent=false | update_core |
| `wp-mcp/list-available-updates` | Read | Report every pending core, plugin, theme and translation update with its new version and WP/PHP compatibility | None | None | core, plugins[], themes[], translations[], can_view_core, can_view_plugins, can_view_themes, can_view_translations, plugins_truncated, themes_truncated, translations_truncated | readonly=true, destructive=false, idempotent=true | update_core, update_plugins, update_themes, or update_languages |
| `wp-mcp/update-translations` | Write | Install every pending translation (language pack) update | None | None | results[] (type, slug, language, version, updated, error), requested, updated | readonly=false, destructive=true, idempotent=false | update_languages |

### 4s. Settings — General, Writing, Reading, Discussion, Media, Permalinks, Privacy (Issue #10)

16 abilities covering every wp-admin settings screen through a declarative,
hand-maintained allowlist in `WP_MCP_Settings`. There is deliberately **no
`get-option` / `update-option`**: a client never supplies a WordPress option
name, only a *field* from a fixed per-group vocabulary that the plugin maps
to options server-side. An unknown field is refused with
`wp_mcp_settings_unknown_field` before anything is read or written, and
`WP_MCP_Settings::never_writable_options()` is a second, independent gate
applied at write time so a future bad allowlist entry cannot open a denied
option. Every ability's input and output schema is generated from that same
allowlist and is closed (`additionalProperties: false`).

Each field declares its type, range and — where applicable — a closed enum;
validation happens before the write, never as a side effect of it. Every
group requires `manage_options` except Privacy, which requires the specific
`manage_privacy_options` capability. Writes are audited per changed field
with old and new value, except `moderation_keys` / `disallowed_keys`, whose
values are recorded as `(omitted)`.

Deliberate exclusions, each for a stated reason:

- `admin_email` is read-only. WordPress changes it only through an emailed
  confirmation link (`new_admin_email` plus a wp-admin-only handler);
  writing the option directly bypasses that anti-takeover confirmation, and
  writing `new_admin_email` outside wp-admin sends no email at all.
- `siteurl` / `home` are read-only: changing either can lock every user out
  of the site and is a redirect/takeover vector.
- Post via e-mail (`mailserver_url` / `mailserver_login` / `mailserver_pass`
  / `mailserver_port`) is not exposed at all — the group stores a plaintext
  mail-server password, and issue #10 forbids surfacing option-held secrets.
- `upload_path` / `upload_url_path` are not exposed: filesystem path
  configuration is never accepted from an MCP client.
- `default_role` refuses any role holding an administrative capability
  (`manage_options`, `edit_users`, `unfiltered_html`, ...), since open
  registration would otherwise become privilege escalation.
- `permalink_structure` accepts only a fixed set of rewrite tags (`%year%`,
  `%monthnum%`, `%day%`, `%hour%`, `%minute%`, `%second%`, `%post_id%`,
  `%postname%`, `%category%`, `%author%`) and requires `%postname%` or
  `%post_id%`. Flushing rewrite rules is the separate
  `wp-mcp/flush-rewrite-rules` ability, never an implicit side effect.

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/get-general-settings` | Read | Read the General settings allowlist | None | None | site_title, tagline, admin_email (read-only), site_url (read-only), home_url (read-only), timezone, timezone_string (read-only), gmt_offset (read-only), date_format, time_format, week_starts_on, language, users_can_register, default_role | readonly=true, destructive=false, idempotent=true | manage_options |
| `wp-mcp/update-general-settings` | Write | Update allowlisted General fields | None | site_title, tagline, timezone, date_format, time_format, week_starts_on, language, users_can_register, default_role | updated, settings | readonly=false, destructive=true, idempotent=true | manage_options |
| `wp-mcp/get-writing-settings` | Read | Read the Writing settings allowlist | None | None | default_category, default_post_format, update_services | readonly=true, destructive=false, idempotent=true | manage_options |
| `wp-mcp/update-writing-settings` | Write | Update allowlisted Writing fields | None | default_category, default_post_format, update_services | updated, settings | readonly=false, destructive=true, idempotent=true | manage_options |
| `wp-mcp/get-reading-settings` | Read | Read the Reading settings allowlist | None | None | show_on_front, page_on_front, page_for_posts, posts_per_page, posts_per_rss, rss_use_excerpt, search_engine_visible | readonly=true, destructive=false, idempotent=true | manage_options |
| `wp-mcp/update-reading-settings` | Write | Update allowlisted Reading fields; enforces the static front page invariants | None | show_on_front, page_on_front, page_for_posts, posts_per_page, posts_per_rss, rss_use_excerpt, search_engine_visible | updated, settings | readonly=false, destructive=true, idempotent=true | manage_options |
| `wp-mcp/get-discussion-settings` | Read | Read the Discussion settings allowlist | None | None | 23 comment, moderation and avatar fields | readonly=true, destructive=false, idempotent=true | manage_options |
| `wp-mcp/update-discussion-settings` | Write | Update allowlisted Discussion fields | None | default_pingback_flag, default_ping_status, default_comment_status, require_name_email, comment_registration, close_comments_for_old_posts, close_comments_days_old, thread_comments, thread_comments_depth, page_comments, comments_per_page, default_comments_page, comment_order, comments_notify, moderation_notify, comment_moderation, comment_previously_approved, comment_max_links, moderation_keys, disallowed_keys, show_avatars, avatar_rating, avatar_default | updated, settings | readonly=false, destructive=true, idempotent=true | manage_options |
| `wp-mcp/get-media-settings` | Read | Read the Media settings allowlist | None | None | thumbnail_size_w, thumbnail_size_h, thumbnail_crop, medium_size_w, medium_size_h, large_size_w, large_size_h, uploads_use_yearmonth_folders | readonly=true, destructive=false, idempotent=true | manage_options |
| `wp-mcp/update-media-settings` | Write | Update allowlisted Media fields | None | thumbnail_size_w, thumbnail_size_h, thumbnail_crop, medium_size_w, medium_size_h, large_size_w, large_size_h, uploads_use_yearmonth_folders | updated, settings | readonly=false, destructive=true, idempotent=true | manage_options |
| `wp-mcp/get-permalink-settings` | Read | Read the Permalink settings allowlist | None | None | permalink_structure, category_base, tag_base | readonly=true, destructive=false, idempotent=true | manage_options |
| `wp-mcp/update-permalink-settings` | Write | Update the permalink structure and rewrite bases; does NOT flush rewrite rules | None | permalink_structure, category_base, tag_base | updated, settings | readonly=false, destructive=true, idempotent=true | manage_options |
| `wp-mcp/get-privacy-settings` | Read | Read the Privacy settings allowlist | None | None | privacy_policy_page_id, privacy_policy_page_title (read-only), privacy_policy_page_url (read-only) | readonly=true, destructive=false, idempotent=true | manage_privacy_options |
| `wp-mcp/update-privacy-settings` | Write | Set or clear the privacy policy page | None | privacy_policy_page_id | updated, settings | readonly=false, destructive=true, idempotent=true | manage_privacy_options |
| `wp-mcp/flush-rewrite-rules` | Write | Regenerate the site's rewrite rules as an explicit, separate operation | None | hard | flushed, hard, permalink_structure | readonly=false, destructive=true, idempotent=true | manage_options |
| `wp-mcp/list-settings-fields` | Read | Describe the settings allowlist itself — group, field, type, accepted values, writability — without returning any stored value | None | group | fields, total | readonly=true, destructive=false, idempotent=true | manage_options |

### 4t. Custom Post Types and Registered Post Metadata (Issue #11)

20 abilities covering discovery of registered post types and their metadata
surface, then list/search, read, create, update, lifecycle, revisions,
featured media and registered post metadata for the types this plugin may
operate on. Implemented in `WP_MCP_Post_Types`.

**The post type is a validated selector, never a dispatcher.** The ability set
is fixed at 20 and does not grow with the site's registered post types: a
per-type ability would make the catalog, the permission matrix and the MCP
tool list depend on installed plugins. `post_type` is resolved through
`get_post_type_object()` on every call, and an unregistered name is refused
with `wp_mcp_invalid_post_type` before anything else happens.

**No hardcoded post/page capabilities.** Every gate comes from the target
type's own registration:

- creation checks `cap->create_posts` on the type object — never the literal
  `edit_posts`, and never a guess that `create_posts` equals `edit_posts`
  (a type may declare them separately).
- publishing checks `cap->publish_posts`; listing non-public statuses checks
  `cap->edit_posts` and narrows results to the caller's own entries without
  `cap->edit_others_posts`.
- per-object access uses the native `read_post` / `edit_post` /
  `delete_post` meta-capabilities, which core's `map_meta_cap()` resolves
  *through that same post type object* — including types registered with
  `map_meta_cap => false`, for which core falls back to the type's own
  declared singular capability.

The practical consequence, and the reason this matters: a user holding core's
`edit_posts` / `publish_posts` has **no** rights over a post type registered
with its own `capability_type`, and a user holding that type's capabilities
has no rights over core posts. The Issue #11 test suite asserts both
directions.

**Which post types are addressable.** Discovery reports every registered post
type; operations accept only the ones this domain owns. A type is refused
with `wp_mcp_post_type_not_operable` and a stable machine-readable `reason`
when it is:

- `dedicated_domain` — `post`, `page`, `attachment`, `revision`,
  `nav_menu_item`, `wp_block`, `wp_template`, `wp_template_part`,
  `wp_global_styles`, `wp_navigation`, `wp_font_family`, `wp_font_face`.
  These have their own explicit ability domains (§4a–§4o) with type-specific
  rules; a second generic path to the same objects would be a weaker
  duplicate of those rules.
- `built_in` — any other core `_builtin` type.
- `not_show_in_rest` — the type never opted into programmatic exposure.
  Discovery still describes it, so an agent learns the boundary instead of
  guessing at it.

**Supports is enforced, not assumed.** `title` / `content` / `excerpt` are
accepted only when the type declares `title` / `editor` / `excerpt` support;
revisions and featured-media abilities require `revisions` and `thumbnail`
support. A field or operation the type does not support is refused with
`wp_mcp_post_type_unsupported_feature` rather than silently written to a
column or a `_thumbnail_id` nothing will ever render.

**Registered post metadata only.** There is deliberately **no
`get-post-meta(key)` / `update-post-meta(key)` for arbitrary keys**. A key is
operable only when it is registered for the requested subtype (or globally
for all post types), exposed via `show_in_rest`, stored as `single => true`,
and not protected. Everything else is *discoverable* through
`wp-mcp/list-post-type-meta-fields` with a stable reason, and refused at
execution:

- `protected_key` — any `_`-prefixed key, or a key `is_protected_meta()`
  reports as protected. Checked **before** the registry is consulted, so
  `_thumbnail_id`, `_edit_lock`, `_wp_page_template` and friends are never
  addressable, registered or not (`wp_mcp_post_meta_protected`).
- `not_show_in_rest` — registered but never opted into API exposure
  (`wp_mcp_post_meta_not_registered`).
- `multi_value` — `single => false`, so there is no single well-defined
  value to read, write, or delete (`wp_mcp_post_meta_not_operable`).

Values are validated *and* sanitized against the key's own registered REST
schema — strings, integers, numbers, booleans, arrays and objects all work,
including closed object schemas, because the shape comes from the
registration rather than from this plugin. Writes additionally check the
native per-object, per-key `edit_post_meta` capability and deletions check
`delete_post_meta`, so a key whose own `auth_callback` refuses the caller is
refused even when that caller can edit the entry itself. Only the
`*_post_meta()` family is ever called: user, term, option, site and network
metadata are out of scope for this domain entirely.

Other deliberate boundaries: creation always produces a **draft** owned by
the **current user** (`post_status` and `post_author` are never accepted from
input, exactly as for posts and pages — publishing is the separate
`wp-mcp/publish-custom-post` ability); an entry ID that belongs to a
different post type is reported as `wp_mcp_invalid_custom_post`, which never
confirms that the ID exists somewhere else; and post/page attributes
(parent, menu order, template, sticky, password) are read-only here.

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/list-post-types` | Read | Discover registered post types with labels, supports, taxonomies, visibility, native capabilities, and this domain's operability verdict | None | operable_only, supports, taxonomy | post_types, total | readonly=true, destructive=false, idempotent=true | read |
| `wp-mcp/get-post-type` | Read | Get one registered post type, including the machine-readable reason when it is not addressable | post_type | None | name, label, singular_label, description, hierarchical, public, show_ui, show_in_rest, has_archive, built_in, map_meta_cap, capability_type, supports, taxonomies, capabilities, permissions, operable, reason | readonly=true, destructive=false, idempotent=true | read |
| `wp-mcp/list-post-type-meta-fields` | Read | Describe the post metadata registered for one post type without returning any stored value | post_type | None | post_type, fields, total | readonly=true, destructive=false, idempotent=true | read |
| `wp-mcp/list-custom-posts` | Read | List and search entries of one registered custom post type | post_type | status, search, taxonomy, term_id, orderby, order, page, per_page (max 50) | post_type, posts, total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | read + post type `cap->edit_posts` for non-public statuses |
| `wp-mcp/get-custom-post` | Read | Get one entry only when its ID belongs to the explicitly requested post type | post_type, post_id | None | id, post_type, title, status, author, slug, date, modified, link, content, excerpt, parent, menu_order, featured_media, supports, terms, meta | readonly=true, destructive=false, idempotent=true | `read_post` (post type) |
| `wp-mcp/create-custom-post` | Write | Create a draft entry; status and author are always set server-side | post_type | title, content, excerpt | id, post_type, title, status, author, slug, date, modified, link | readonly=false, destructive=false, idempotent=false | post type `cap->create_posts` |
| `wp-mcp/update-custom-post` | Write | Update the supported content fields of one entry | post_type, post_id | title, content, excerpt | Entry summary | readonly=false, destructive=true, idempotent=true | `edit_post` (post type) |
| `wp-mcp/publish-custom-post` | Write | Publish one entry. Idempotent | post_type, post_id | None | Entry summary | readonly=false, destructive=true, idempotent=true | post type `cap->publish_posts` + `edit_post` |
| `wp-mcp/unpublish-custom-post` | Write | Return one published entry to draft. Idempotent | post_type, post_id | None | Entry summary | readonly=false, destructive=true, idempotent=true | `edit_post` (post type) |
| `wp-mcp/trash-custom-post` | Write | Move one entry to trash. Idempotent | post_type, post_id | None | Entry summary | readonly=false, destructive=true, idempotent=true | `delete_post` (post type) |
| `wp-mcp/restore-custom-post` | Write | Restore one entry from trash. Idempotent | post_type, post_id | None | Entry summary | readonly=false, destructive=true, idempotent=true | `delete_post` (post type) |
| `wp-mcp/delete-custom-post-permanently` | Write | Permanently delete one entry, bypassing trash | post_type, post_id | None | post_type, id, deleted | readonly=false, destructive=true, idempotent=false | `delete_post` (post type) |
| `wp-mcp/list-custom-post-revisions` | Read | List revisions of one entry; requires `revisions` support | post_type, post_id | page, per_page | post_type, post_id, revisions, total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | `edit_post` (post type) |
| `wp-mcp/get-custom-post-revision` | Read | Get one revision, only when it belongs to the requested entry | post_type, post_id, revision_id | None | post_type, id, parent_id, author, title, content, excerpt, date, modified | readonly=true, destructive=false, idempotent=true | `edit_post` (post type) |
| `wp-mcp/restore-custom-post-revision` | Write | Restore one entry to a previous revision | post_type, post_id, revision_id | None | Entry summary | readonly=false, destructive=true, idempotent=false | `edit_post` (post type) |
| `wp-mcp/set-custom-post-featured-image` | Write | Set an existing image attachment as featured media; requires `thumbnail` support. Idempotent | post_type, post_id, media_id | None | post_type, post_id, media_id, media_url | readonly=false, destructive=true, idempotent=true | `edit_post` (post type) + `read_post` (attachment) |
| `wp-mcp/remove-custom-post-featured-image` | Write | Remove the entry's featured media. Idempotent | post_type, post_id | None | post_type, post_id, removed_media_id, featured_media_id | readonly=false, destructive=true, idempotent=true | `edit_post` (post type) |
| `wp-mcp/get-custom-post-meta` | Read | Read one registered, REST-visible, single-valued post metadata key | post_type, post_id, meta_key | None | post_type, post_id, meta_key, value | readonly=true, destructive=false, idempotent=true | `read_post` (post type) |
| `wp-mcp/update-custom-post-meta` | Write | Write one registered post metadata key after validating it against that key's own registered schema | post_type, post_id, meta_key, value | None | post_type, post_id, meta_key, value | readonly=false, destructive=true, idempotent=true | `edit_post` + `edit_post_meta` |
| `wp-mcp/delete-custom-post-meta` | Write | Delete one registered post metadata key. Idempotent when absent | post_type, post_id, meta_key | None | post_type, post_id, meta_key, deleted | readonly=false, destructive=true, idempotent=true | `edit_post` + `delete_post_meta` |

### 4u. System, Cron, Cache/Maintenance, Import/Export and Privacy (Issue #12)

31 abilities covering the operational WordPress tooling that sits outside
content and settings. Implemented in `WP_MCP_System` (inspection, alongside
the issue #9 core updates), `WP_MCP_Cron`, `WP_MCP_Maintenance`,
`WP_MCP_Import_Export` and `WP_MCP_Privacy`.

**No ability in this group accepts a path, a filename, a URL, a callback or
function name, a WordPress option name, a transient name, a cache group, or a
SQL fragment.** That is enforced by closed schemas plus runtime validation, not
by convention, and a test asserts it against every registered input field.

#### Inspection (7)

Responses never contain a filesystem path — not `ABSPATH`, not the uploads
directory, not the location of an `.htaccess` — and never a salt, key or
credential; debug flags are reported as booleans, never as log locations.
Directory sizes come from `WP_REST_Site_Health_Controller::get_directory_sizes()`
and are copied member by member (`raw`, `size`), so a future core change that
added a `path` member could not leak one through this plugin.

**Site Health tests are a validated selector, never a callback.** Only the
`direct` tests of WordPress's own `site_status_tests` registry whose `test`
member is a *string* resolving to a real `WP_Site_Health::get_test_*` method
are run. An asynchronous test, or a third-party test registered as a callable,
is listed with `runnable: false` and a stable `reason` and is never invoked.

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/get-site-info` | Read | Site identity, active theme and content counts | None | None | name, description, home_url, site_url, wp_version, locale, timezone_string, gmt_offset, charset, is_multisite, search_engine_visible, active_theme, active_theme_slug, active_theme_version, is_block_theme, published_posts, published_pages, media_items, approved_comments, pending_comments, total_users | readonly=true, destructive=false, idempotent=true | `manage_options` |
| `wp-mcp/get-environment-info` | Read | PHP, WordPress and database environment: versions, limits and capability flags | None | None | wp_version, environment_type, is_multisite, php_version, php_sapi, php_memory_limit, php_max_execution_time, php_upload_max_filesize, php_post_max_size, php_max_input_vars, wp_memory_limit, wp_max_memory_limit, wp_debug, wp_debug_log_enabled, script_debug, database_server_version, database_charset, database_collate, https_home_url, external_object_cache, curl_available, openssl_version, imagick_available, gd_available, server_time_utc | readonly=true, destructive=false, idempotent=true | `manage_options` |
| `wp-mcp/list-image-sizes` | Read | Image sub-sizes WordPress generates on upload | None | None | sizes (name, width, height, crop), total | readonly=true, destructive=false, idempotent=true | `manage_options` |
| `wp-mcp/get-rewrite-state` | Read | Permalink structure, rewrite bases and server rewrite support | None | None | permalink_structure, using_permalinks, using_index_permalinks, front, root, category_base, tag_base, author_base, search_base, pagination_base, comments_base, feed_base, cached_rules, server_supports_rewrite, url_rewrite_available | readonly=true, destructive=false, idempotent=true | `manage_options` |
| `wp-mcp/get-site-size` | Read | Size of the WordPress directories and the database. Sizes only, never the measured paths. Slow on large sites | None | None | entries (name, raw_bytes, size), total | readonly=true, destructive=false, idempotent=true | `manage_options` |
| `wp-mcp/list-site-health-tests` | Read | Registered Site Health tests and which of them this plugin will run | None | None | tests (name, label, group, runnable, reason), total, runnable | readonly=true, destructive=false, idempotent=true | `view_site_health_checks` |
| `wp-mcp/run-site-health-tests` | Read | Run WordPress's own direct Site Health tests | None | tests (subset of runnable names) | results (name, label, status, badge_label, badge_color, description), total, good, recommended, critical | readonly=true, destructive=false, idempotent=true | `view_site_health_checks` |

#### Cron (7)

A hook is a selector validated against WordPress's own registries, never a
dispatcher. `run-cron-event` fires only hooks that are **already queued** in
the site's cron array — work WordPress would run unattended anyway — and
since issue #15 `schedule-cron-event` accepts only two kinds of hook: a
WordPress core maintenance hook **with no arguments at all**
(`wp_version_check`, `wp_update_plugins`, `wp_scheduled_delete`,
`delete_expired_transients`, …), or a hook **and argument set the site
already has queued**, whose timing is then changed. The no-arguments rule
is load-bearing: core's own callbacks for those names take none, so an
argument supplied here could only ever reach a third-party plugin that
hooked the same name, which would make the fixed core list a general
dispatcher wearing a core hook's name.
`has_action()` is deliberately *not* the policy: "any hook something listens
to" covers most of WordPress and every installed plugin, so `schedule` plus
`run` would together add up to an `execute(action, args)` dispatcher — the
shape epic #1 refuses. A site owner opts one of their own hooks in
server-side through the `wp_mcp_cron_schedulable_hooks` filter, which MCP
input can never reach. On top of that sits a permanent denylist:

- **Unattended mutation:** `wp_maybe_auto_update`, `upgrader_scheduled_cleanup`,
  `wp_delete_temp_updater_backups`. The issue #9 update abilities are the
  explicit, reviewable way to update.
- **Request-lifecycle actions**, which are not cron jobs at all:
  `muplugins_loaded`, `plugins_loaded`, `setup_theme`, `after_setup_theme`,
  `init`, `wp_loaded`, `parse_request`, `send_headers`, `wp`,
  `template_redirect`, `wp_head`, `wp_footer`, `shutdown`.
- **Prefixes:** `wp_ajax_`, `wp_ajax_nopriv_`, `admin_`, `rest_`, `load-`.

Event arguments are bounded scalars only — at most 10, each string at most 255
characters. Arrays, objects and serialized payloads are refused. Every cron
ability is gated on `manage_options`, so firing a queued job grants no
capability the caller does not already hold in wp-admin.

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/get-cron-status` | Read | WP-Cron configuration, queue summary, next event and registered schedules | None | None | wp_cron_disabled, alternate_wp_cron, cron_lock_active, total_events, due_events, next_event_hook, next_event_timestamp, next_event_utc, server_time_utc, schedules | readonly=true, destructive=false, idempotent=true | `manage_options` |
| `wp-mcp/list-cron-events` | Read | Scheduled events, oldest first, with the operability verdict per hook | None | hook, due_only, page, per_page | events, total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | `manage_options` |
| `wp-mcp/get-cron-event` | Read | One scheduled event, narrowed by signature or timestamp | hook | signature, timestamp | hook, signature, timestamp, scheduled_utc, schedule, interval, args_json, args_count, is_due, operable, reason | readonly=true, destructive=false, idempotent=true | `manage_options` |
| `wp-mcp/schedule-cron-event` | Write | Schedule a one-off or recurring event for a core maintenance hook, or retime a hook+args pair the site already has queued; replaces rather than duplicates | hook | timestamp, recurrence, args | event, replaced, scheduled | readonly=false, destructive=true, idempotent=true | `manage_options` |
| `wp-mcp/unschedule-cron-event` | Write | Remove one event, or every occurrence of a hook | hook | signature, timestamp, all_occurrences | hook, removed, remaining | readonly=false, destructive=true, idempotent=false | `manage_options` |
| `wp-mcp/run-cron-event` | Write | Run one already-queued event now, rescheduling a recurring one exactly as WordPress's cron runner does | hook | signature, timestamp | hook, timestamp, rescheduled, schedule, ran | readonly=false, destructive=true, idempotent=false | `manage_options` |
| `wp-mcp/run-due-cron-events` | Write | Run every currently due event, skipping denylisted and listenerless hooks with a reason | None | limit (1-50) | events, ran, skipped, limit | readonly=false, destructive=true, idempotent=false | `manage_options` |

#### Cache and maintenance (5)

Every operation is fixed and individually named: **there is no
`delete-transient( key )`** and no ability that accepts a transient name, an
option name or a cache group. `clear-expired-transients` removes only expired
entries, through the same routine core schedules for itself; note that core
deletes those rows with a single SQL statement and does not invalidate the
options cache, so a persistent object cache can keep serving an already-deleted
value until it is flushed — `flush-object-cache` is the explicit follow-up.

Maintenance mode goes through `WP_Upgrader::maintenance_mode()` and the
`WP_Filesystem` abstraction — the same code path core uses during an update —
never through direct file writes, and is gated on `update_core` rather than
`manage_options` because the `.maintenance` marker lives at the WordPress root
and takes an entire multisite network offline. WordPress stops honouring the
marker after ten minutes on its own, so a forgotten `enabled: true` self-heals.

**Database repair/optimize is deliberately not exposed.** WordPress ships no
capability-safe API for it: `wp-admin/maint/repair.php` is a standalone screen
gated on the `WP_ALLOW_REPAIR` constant, not a function, and every other route
would mean running SQL composed by or supplied to an ability, which §7 forbids
outright.

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/flush-object-cache` | Write | Flush the object cache. Takes no cache group | None | None | flushed, external_object_cache, persistent_cache_available | readonly=false, destructive=true, idempotent=true | `manage_options` |
| `wp-mcp/clear-expired-transients` | Write | Delete every *expired* transient through core's own cleanup routine | None | None | cleared, scope, external_object_cache | readonly=false, destructive=true, idempotent=true | `manage_options` |
| `wp-mcp/clear-update-caches` | Write | Drop the cached core/plugin/theme update check results | None | None | cleared, available | readonly=false, destructive=true, idempotent=true | `manage_options` |
| `wp-mcp/get-maintenance-mode` | Read | Whether the maintenance page is being served, since when, and when core stops honouring the marker | None | None | enabled, marker_present, enabled_since_utc, expires_utc, window_seconds | readonly=true, destructive=false, idempotent=true | `manage_options` |
| `wp-mcp/set-maintenance-mode` | Write | Enable or disable maintenance mode through core's upgrader and WP_Filesystem | enabled | None | enabled, marker_present, enabled_since_utc, expires_utc, window_seconds | readonly=false, destructive=true, idempotent=true | `update_core` |

#### Import and export (4)

No path, filename or URL field exists: the export is returned as a string, and
the import is supplied as one, written to a WordPress-managed temporary file
through `WP_Filesystem` and deleted before the ability returns. Attachment
fetching is forced off, so the importer never resolves a URL found inside
caller-supplied XML.

WordPress core ships **no WXR importer** — the parser and importer live in the
official WordPress Importer plugin — so `import-content` refuses with
`wp_mcp_import_unsupported` when that plugin is not active, rather than
reimplementing a parser. The document is parsed with the importer's own parser
before the importer runs, because `WP_Import` calls `die()` on a malformed
document and that would kill the request.

Settings import/export is exactly the issue #10 allowlist: an unknown field or
a read-only field is refused the same way `update-general-settings` refuses it,
and a payload never carries a WordPress option name.

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/export-content` | Read | Generate a WXR export with core's exporter and return it as a string | None | content, author, category, status, start_date, end_date | wxr, bytes, content, status, author, category, start_date, end_date, generated_utc, max_bytes | readonly=true, destructive=false, idempotent=true | `export` |
| `wp-mcp/import-content` | Write | Import a WXR document supplied as a string, with attachment fetching forced off | wxr | None | imported, imported_posts, imported_terms, imported_authors, fetched_attachments | readonly=false, destructive=true, idempotent=false | `import` |
| `wp-mcp/export-settings` | Read | Export every allowlisted, writable settings field, grouped by screen | None | None | settings, groups, skipped_groups, generated_utc | readonly=true, destructive=false, idempotent=true | `manage_options` + per-group capability |
| `wp-mcp/import-settings` | Write | Write back a settings payload through the same validated writers the settings abilities use | settings | None | applied, updated_total | readonly=false, destructive=true, idempotent=true | `manage_options` + per-group capability |

#### Privacy (8)

**Consent is never bypassed.** A request is always created in the
`request-pending` status, exactly as wp-admin creates it; there is no ability
that marks a request confirmed, and `process-*` refuses an unconfirmed request
with `wp_mcp_privacy_request_invalid_state`. `WP_User_Request::$confirm_key` —
the secret behind the confirmation link — appears in no response and is
accepted in no input.

The exporters and erasers that run are whatever WordPress's own
`wp_privacy_personal_data_exporters` / `wp_privacy_personal_data_erasers`
registries contain; a caller never names, adds or selects one. The per-page
accumulation, grouping, file generation and completion are core's: this domain
drives `wp_privacy_process_personal_data_export_page()` and
`wp_privacy_process_personal_data_erasure_page()`, the same functions core's
own Ajax handlers drive, rather than reimplementing them.

The privacy *policy page* setting is not here — it is an explicit field of the
issue #10 settings allowlist (`get-privacy-settings` / `update-privacy-settings`).

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/list-privacy-requests` | Read | List personal data requests of the types the caller may see | None | type, status, page, per_page | requests, total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | `export_others_personal_data` or `erase_others_personal_data` |
| `wp-mcp/get-privacy-request` | Read | Read one request; the confirmation key is never returned | request_id | None | id, type, action_name, email, user_id, status, created_utc, modified_utc, confirmed_utc, completed_utc, export_file_url | readonly=true, destructive=false, idempotent=true | `export_others_personal_data` or `erase_others_personal_data` |
| `wp-mcp/create-privacy-export-request` | Write | Create an export request, always pending | email | send_confirmation_email | request, confirmation_sent, confirmation_error | readonly=false, destructive=false, idempotent=false | `export_others_personal_data` |
| `wp-mcp/create-privacy-erasure-request` | Write | Create an erasure request, always pending | email | send_confirmation_email | request, confirmation_sent, confirmation_error | readonly=false, destructive=false, idempotent=false | `erase_others_personal_data` |
| `wp-mcp/resend-privacy-request-email` | Write | Re-send the confirmation email for a pending or failed request | request_id | None | request, sent | readonly=false, destructive=false, idempotent=true | `export_others_personal_data` or `erase_others_personal_data` |
| `wp-mcp/process-privacy-export-request` | Write | Run every registered exporter for a confirmed request and let core assemble the export file | request_id | send_as_email | request, exporters_run, pages_processed, items_exported, failed_exporters, sent_as_email, export_file_url | readonly=false, destructive=false, idempotent=false | `export_others_personal_data` |
| `wp-mcp/process-privacy-erasure-request` | Write | Run every registered eraser for a confirmed request | request_id | None | request, erasers_run, pages_processed, items_removed, items_retained, messages, failed_erasers | readonly=false, destructive=true, idempotent=false | `erase_others_personal_data` |
| `wp-mcp/delete-privacy-request` | Write | Permanently delete a request record, as wp-admin's Remove request does | request_id | None | id, type, deleted | readonly=false, destructive=true, idempotent=false | `export_others_personal_data` or `erase_others_personal_data` |

### 4v. Multisite — Network Administration (Issue #13)

30 abilities covering WordPress Network Admin: sites, network users and
their per-site memberships, network plugins and themes, network settings,
and the network update state. Implemented in `WP_MCP_Network`,
`WP_MCP_Network_Sites`, `WP_MCP_Network_Users`,
`WP_MCP_Network_Extensions` and `WP_MCP_Network_Settings`, all registered
under the `wp-mcp-network` category.

**Single-site installations degrade cleanly.** Every one of these abilities
is registered on a single site too — so the permission matrix and the
registered surface can be verified against each other in either environment
— but off a network its permission callback is `false`, so no MCP client is
ever offered the tool, and its callback answers
`wp_mcp_network_unsupported` (HTTP 501). The multisite check runs *before*
the capability check on purpose: a single-site installation must never be
told it lacks a network capability, which would send an agent looking for
credentials to a network that does not exist.

`wp-mcp/get-network-info` is the single deliberate exception: it answers on
both kinds of installation, reporting `is_multisite: false` and neutral
values, so an agent can discover which world it is in before trying anything
else.

**Nothing here assumes Super Admin.** Every operation checks the concrete
network capability WordPress itself uses for that screen — `manage_sites`,
`manage_network_users`, `manage_network_plugins`, `manage_network_themes`,
`manage_network_options`, `upgrade_network`, plus `create_sites`,
`delete_sites`, `create_users` and `delete_users` where core requires them —
never a blanket `manage_options`, and never "authenticated, therefore Super
Admin". A site administrator of one site of the network holds none of them.

#### Network inspection and updates (3)

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/get-network-info` | Read | Network identity, addressing mode, site and user counts. Answers on single-site too, with `is_multisite: false` | None | None | is_multisite, network_id, network_name, network_home_url, network_site_url, subdomain_install, main_site_id, current_site_id, site_count, user_count, current_user_is_super_admin, upgrade_required | readonly=true, destructive=false, idempotent=true | `manage_network` (multisite) / `manage_options` (single site) |
| `wp-mcp/get-network-update-status` | Read | Whether the per-site database upgrade that follows a core update is still pending, plus network-wide update counts | None | None | upgrade_required, wp_db_version, network_db_version, sites_total, core_update_available, plugin_updates, theme_updates, network_activated_count | readonly=true, destructive=false, idempotent=true | `manage_network` |
| `wp-mcp/upgrade-network-sites` | Write | Run core's own per-site database upgrade over a bounded batch of sites. Idempotent — core skips a site already at the current version | None | max_sites (1–50, default 25), offset | upgraded, upgraded_count, offset, next_offset, remaining, sites_total, network_db_version | readonly=false, destructive=false, idempotent=true | `upgrade_network` |

`upgrade-network-sites` is deliberately **not** the HTTP fan-out over every
site's `upgrade.php` that the wp-admin screen performs: that would mean this
plugin issuing requests to caller-influenced URLs. It switches to each site
and calls core's `wp_upgrade()`, which returns immediately for a site that is
already current.

#### Sites (11)

A site is addressed by `site_id` and created from a `slug`. **No ability in
this group accepts a domain, a path or a URL**: the address of a new site is
derived from the network's own configuration exactly as
`wp-admin/network/site-new.php` does, and the domain and path of an existing
site are never writable — moving a live site to another address is a
migration, not a metadata edit. A client that could name the domain could
point a site at a host of its choosing, which is a redirect and cookie-scope
problem, not a convenience.

The **main site of the network is never archived, deactivated, marked as spam
or deleted**, and neither is the site the request is currently running on:
both would end the network administrator's own access mid-request. WordPress's
own Network Admin hides those actions for the main site for the same reason.

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/list-network-sites` | Read | List the sites of the network, bounded pagination | None | search, status (all/active/public/archived/mature/spam/deleted), page, per_page (max 50) | sites (id, network_id, domain, path, url, name, registered_gmt, last_updated_gmt, public, archived, mature, spam, deleted, is_main_site, post_count), total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | `manage_sites` |
| `wp-mcp/get-network-site` | Read | Read one site of the network | site_id | None | Same site fields | readonly=true, destructive=false, idempotent=true | `manage_sites` |
| `wp-mcp/create-network-site` | Write | Create a site from a slug, with an existing user as its administrator | slug, title, admin_user_id | public | Site fields | readonly=false, destructive=false, idempotent=false | `manage_sites` + `create_sites` |
| `wp-mcp/update-network-site` | Write | Change the supported metadata: title, public flag, mature flag | site_id | title, public, mature | Site fields | readonly=false, destructive=true, idempotent=true | `manage_sites` |
| `wp-mcp/archive-network-site` | Write | Archive a site; its data is untouched | site_id | None | Site fields | readonly=false, destructive=true, idempotent=true | `manage_sites` |
| `wp-mcp/unarchive-network-site` | Write | Clear the archived flag | site_id | None | Site fields | readonly=false, destructive=true, idempotent=true | `manage_sites` |
| `wp-mcp/activate-network-site` | Write | Clear the deleted flag — Network Admin's "Activate" | site_id | None | Site fields | readonly=false, destructive=true, idempotent=true | `manage_sites` |
| `wp-mcp/deactivate-network-site` | Write | Set the deleted flag — Network Admin's "Deactivate". Reversible; nothing is removed | site_id | None | Site fields | readonly=false, destructive=true, idempotent=true | `manage_sites` |
| `wp-mcp/mark-network-site-spam` | Write | Flag a site as spam | site_id | None | Site fields | readonly=false, destructive=true, idempotent=true | `manage_sites` |
| `wp-mcp/unmark-network-site-spam` | Write | Clear the spam flag | site_id | None | Site fields | readonly=false, destructive=true, idempotent=true | `manage_sites` |
| `wp-mcp/delete-network-site` | Write | Permanently delete a site, its tables and its uploads | site_id | None | id, domain, path, deleted | readonly=false, destructive=true, idempotent=false | `manage_sites` + `delete_sites` |

#### Network users (8)

**Super Admin is never granted or revoked here.** `grant_super_admin()` /
`revoke_super_admin()` are deliberately not exposed: granting the network's
highest privilege is the one operation whose blast radius is the entire
network, and it stays a human decision made in Network Admin. A Super Admin
account is also refused as a deletion target, so this domain can never remove
the people who could undo its own mistakes.

**Deletion never destroys content implicitly.** WordPress's own
`wpmu_delete_user()` deletes every post the user authored on every site of the
network, so `wp-mcp/delete-network-user` requires an explicit
`reassign_user_id` and reassigns each site's content *before* calling core —
the same contract as the single-site `wp-mcp/delete-user`.

Roles are validated against the *target site*, inside a `switch_to_blog()`
context and through `get_editable_roles()`, because a role that exists on one
site of a network need not exist on another.

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/list-network-users` | Read | Every user of the network, not only of the current site | None | search, page, per_page (max 50) | users (id, username, email, name, registered_gmt, is_super_admin, site_count), total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | `manage_network_users` |
| `wp-mcp/get-network-user` | Read | One network user, with Super Admin status and site count | user_id | None | Same user fields | readonly=true, destructive=false, idempotent=true | `manage_network_users` |
| `wp-mcp/list-network-user-sites` | Read | The sites a user belongs to, with the roles held on each | user_id | page, per_page (max 50) | user_id, sites (site_id, domain, path, url, name, roles), total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | `manage_network_users` |
| `wp-mcp/create-network-user` | Write | Create an account with no site membership; the network's illegal names and limited/banned email domains are enforced by core's own signup validation. The password is never returned | username, email, password | None | User fields | readonly=false, destructive=false, idempotent=false | `manage_network_users` + `create_users` |
| `wp-mcp/delete-network-user` | Write | Delete a user from the whole network after reassigning their content on every site. Refuses Super Admins and the current user | user_id, reassign_user_id | None | id, deleted, reassigned_to, sites_detached | readonly=false, destructive=true, idempotent=false | `manage_network_users` + `delete_users` |
| `wp-mcp/add-user-to-network-site` | Write | Add an existing network user to one site with an explicit role. Idempotent for the same role; a different existing role is refused | site_id, user_id, role | None | site_id, user_id, site_name, site_url, roles | readonly=false, destructive=false, idempotent=true | `manage_network_users` |
| `wp-mcp/remove-user-from-network-site` | Write | Remove a user from one site; the account and every other membership survive | site_id, user_id | reassign_user_id | site_id, user_id, removed, reassigned_to | readonly=false, destructive=true, idempotent=true | `manage_network_users` |
| `wp-mcp/set-network-site-user-role` | Write | Set the complete role a user holds on one site | site_id, user_id, role | None | site_id, user_id, site_name, site_url, roles | readonly=false, destructive=true, idempotent=true | `manage_network_users` |

#### Network plugins and themes (5)

Installing, updating and deleting plugins and themes are deliberately **not**
duplicated here: on a network WordPress already restricts `install_plugins` /
`update_plugins` / `delete_plugins` and their theme counterparts to Super
Admins, so the issue #9 abilities already are the network-level operations. A
second, near-identical surface would be a weaker duplicate of a gate that
already works.

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/network-activate-plugin` | Write | Activate an installed plugin across every site. Idempotent | plugin_file | None | file, name, version, network_active, active_on_current_site | readonly=false, destructive=false, idempotent=true | `manage_network_plugins` |
| `wp-mcp/network-deactivate-plugin` | Write | Deactivate a network-activated plugin. Per-site activations are untouched. Idempotent | plugin_file | None | Same plugin fields | readonly=false, destructive=true, idempotent=true | `manage_network_plugins` |
| `wp-mcp/list-network-themes` | Read | Installed themes with their network-enabled state | None | search, network_enabled, page, per_page (max 50) | themes (stylesheet, name, version, network_enabled, active_on_main_site), total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | `manage_network_themes` |
| `wp-mcp/network-enable-theme` | Write | Make a theme selectable by every site. Never switches any site to it. Idempotent | stylesheet | None | Same theme fields | readonly=false, destructive=false, idempotent=true | `manage_network_themes` |
| `wp-mcp/network-disable-theme` | Write | Stop offering a theme to the network. Refused for the theme active on the main site. Idempotent | stylesheet | None | Same theme fields | readonly=false, destructive=true, idempotent=true | `manage_network_themes` |

#### Network settings (3)

The same declarative allowlist model as the per-site settings of issue #10:
**there is no `get-network-option` / `update-network-option`**, and no ability
accepts a WordPress network option name. Clients address fields from a fixed
vocabulary (`network_name`, `registration`, `banned_email_domains`, …) which
the plugin maps to network options server-side, and an unlisted field is
refused with `wp_mcp_settings_unknown_field` before anything is read or
written. `WP_MCP_Network_Settings::never_writable_options()` is a second,
independent gate applied at write time.

Exposed fields: `network_name`, `network_admin_email` (read-only),
`subdomain_install` (read-only), `registration`, `registration_notification`,
`add_new_users`, `site_admins_can_manage_plugins`, `illegal_names`,
`limited_email_domains`, `banned_email_domains`, `welcome_email`,
`welcome_user_email`, `blog_upload_space`, `upload_space_check_disabled`,
`fileupload_maxk`, `upload_filetypes`, `default_language`.

Deliberate exclusions, each for a stated reason rather than an oversight:

- **`site_admins`** — the list of Super Admins. Writing it is network takeover
  in a single call.
- **`admin_email`** is readable but not writable, and `new_admin_email` is not
  exposed at all: WordPress changes the network admin email only through an
  emailed confirmation link, and writing the option directly would bypass that
  confirmation.
- **`siteurl` and the network's domain/path** are not exposed: changing them
  can lock every site of the network out.
- **`subdomain_install`** is readable but not writable: it is decided at
  install time and backed by a constant, and flipping the stored option alone
  would leave every existing site unreachable.
- **`ms_files_rewriting`, `upload_path`, `upload_url_path`** — filesystem path
  configuration, which this plugin never accepts from an MCP client.
- **The "first post / first page / first comment" templates** — site seeding
  content, not network policy, and reachable through the content abilities
  once a site exists.

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/get-network-settings` | Read | Read every allowlisted network settings field | None | None | The 17 fields listed above | readonly=true, destructive=false, idempotent=true | `manage_network_options` |
| `wp-mcp/update-network-settings` | Write | Update one or more allowlisted fields; an unknown or read-only field is refused without writing anything | None | Any writable field | updated, settings | readonly=false, destructive=true, idempotent=true | `manage_network_options` |
| `wp-mcp/list-network-settings-fields` | Read | Describe the allowlist itself without reading any stored value | None | None | fields (field, writable, type, capability, description, allowed_values, minimum, maximum, max_length), total | readonly=true, destructive=false, idempotent=true | `manage_network_options` |

### 4w. Extensibility — Adapters for Installed Plugins (Issue #14)

Two abilities that describe the integration layer itself, plus 15 abilities
contributed by five adapters and registered **only when their plugin is
present**. Implemented in `WP_MCP_Integration` (the adapter base class),
`WP_MCP_Integrations` (the registry and both manifests),
`WP_MCP_Generic_Integration` (declarative detect-only adapters) and the five
covered adapters under `includes/integrations/`, all registered under the
`wp-mcp-extensibility` category.

**No generic bridge, ever.** There is no `call-plugin-function( name, args )`,
no REST-route executor, no action/filter executor and no option or metadata
reader used to walk around an integrated plugin's own API — epic #1 forbids
all four outright. Every adapter calls the third-party plugin's documented
public API, or core APIs applied to that plugin's own registered post type,
and the complete list of third-party symbols this plugin is allowed to touch
is `stubs/integrations.php`, which is also the PHPStan bootstrap. Anything not
declared there is not called.

**Abilities appear only when the integration is available.**
`WP_MCP_Integrations::register_available_abilities()` asks each adapter's
`detect()` before registering anything, so an MCP client is never offered a
tool whose plugin is not installed. `detect()` is `final` on the base class:
an adapter cannot lie about its own availability. Detection is signal-ordered
— a constant, then a class, then a function, then an *active* plugin file
(`is_plugin_active()`, plus its network variant, never merely "installed") —
and reports which signal answered. A constant whose value is a filesystem path
rather than a version (`TRIBE_EVENTS_FILE`, `MATOMO_ANALYTICS_FILE`) reports no
version rather than leaking the path.

The permission matrix still documents those abilities unconditionally; each
of their rows carries an extra `integration` key, and the consistency test
asserts the ability is **not** registered while that integration is absent.

**Detectable is not the same as covered**, and the plugin publishes both
rather than blurring them. `wp-mcp/list-integrations` answers with two
independent booleans per integration: `detected` (is the plugin here) and
`covered` (do we contribute abilities for it). An agent can therefore tell
"WooCommerce is not installed" from "Rank Math is installed but has no
abilities yet". Which operations of each plugin deserve an ability is decided
per plugin, against a real production inventory, in the follow-up issues of
#14 — never by generalising a bridge.

**Every adapter declares what it does not expose**, detect-only ones included:
the exclusion decision is recorded before anybody writes the first ability for
that plugin. For the sensitive groups it is categorical — SMTP hosts,
usernames, passwords, API keys and OAuth tokens never leave the site under any
circumstance, and traffic, audit and visitor logs are personal data and do not
either.

#### The framework itself (2) — always registered

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/list-integrations` | Read | Every known integration, whether it is detected here, and whether it contributes abilities yet | None | group, detected, covered | integrations[] (slug, label, group, plugin, detected, version, detected_by, covered, ability_count), total, detected_count, covered_count, groups | readonly=true, destructive=false, idempotent=true | `activate_plugins` |
| `wp-mcp/get-integration` | Read | One integration in full: detection signals, contributed abilities, required capabilities and the data it deliberately never exposes | slug | None | slug, label, group, plugin, detected, version, detected_by, plugin_file, covered, abilities, required_capabilities, excluded_data, detection_signals, notes | readonly=true, destructive=false, idempotent=true | `activate_plugins` |

#### WooCommerce (7) — `ecommerce`

Detected through `WC_VERSION`, the `WooCommerce` class, the `WC()` function or
an active `woocommerce/woocommerce.php`. Every read goes through WooCommerce's
documented CRUD API (`wc_get_products()`, `wc_get_product()`,
`wc_get_orders()`, `wc_get_order()`, `WC_Coupon`) — never its tables, its REST
controllers or its post meta. Coupons are listed with core's `WP_Query` over
WooCommerce's own `shop_coupon` post type and then read through `WC_Coupon`,
the one listing route that behaves the same under the legacy and the HPOS
order stores.

**Excluded:** billing and shipping addresses, customer e-mail addresses, phone
numbers and IP addresses; payment transaction references and gateway
credentials; WooCommerce settings and API keys; refunds, order editing and
order deletion. `customer_id` is returned so an agent can correlate orders,
and the issue #7 user abilities remain the one capability-checked route to
anything about that person.

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/woocommerce-get-store-status` | Read | Version, currency, base location and published product, order and coupon counts | None | None | version, currency, base_country, base_state, product_count, order_count, coupon_count, order_statuses | readonly=true, destructive=false, idempotent=true | `manage_woocommerce` (or `manage_options`) |
| `wp-mcp/woocommerce-list-products` | Read | Products with bounded pagination | None | search, status, type, stock_status, page, per_page | products[] (id, name, sku, type, status, price, regular_price, sale_price, stock_status, stock_quantity, manage_stock, date_created), total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | `manage_woocommerce` (or `manage_options`) |
| `wp-mcp/woocommerce-get-product` | Read | One product: pricing, stock, descriptions, categories and tags | product_id | None | the list fields plus description, short_description, permalink, total_sales, categories, tags | readonly=true, destructive=false, idempotent=true | `manage_woocommerce` (or `manage_options`) |
| `wp-mcp/woocommerce-update-product-stock` | Write | Stock quantity and/or stock status of one product. Idempotent; prices are never touched | product_id | stock_quantity, stock_status | the product summary | readonly=false, destructive=true, idempotent=true | `manage_woocommerce` + `edit_post` (product) |
| `wp-mcp/woocommerce-list-orders` | Read | Orders with bounded pagination. Money, status and counts only | None | status, customer_id, page, per_page | orders[] (id, number, status, currency, total, payment_method_title, customer_id, item_count, date_created), total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | `edit_shop_orders` (or `manage_options`) |
| `wp-mcp/woocommerce-get-order` | Read | One order: status, totals, payment method title and line items | order_id | None | the list fields plus total_tax, shipping_total, discount_total, date_paid, items[] | readonly=true, destructive=false, idempotent=true | `edit_shop_orders` (or `manage_options`) |
| `wp-mcp/woocommerce-list-coupons` | Read | Published coupons with bounded pagination | None | search, page, per_page | coupons[] (id, code, discount_type, amount, usage_count, usage_limit, date_expires, description), total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | `manage_woocommerce` (or `manage_options`) |

#### Yoast SEO (2) — `seo`

Reads and writes the per-post SEO fields through `WPSEO_Meta::get_value()` and
`WPSEO_Meta::set_value()`, Yoast's own accessors, which own the `_yoast_wpseo_*`
key naming and the defaults. This adapter never touches those meta keys
directly, and the field vocabulary is fixed and closed: a caller never supplies
a Yoast meta key.

**Excluded:** Yoast site-wide options, connected accounts and API tokens;
indexables and reindexation; redirects (Yoast SEO Premium).

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/yoast-seo-get-post-seo` | Read | The Yoast fields of one post | post_id | None | post_id, title, meta_description, focus_keyphrase, opengraph_title, opengraph_description, twitter_title, twitter_description, breadcrumbs_title, canonical, robots_noindex, robots_nofollow | readonly=true, destructive=false, idempotent=true | `edit_posts` + `edit_post` (post) |
| `wp-mcp/yoast-seo-update-post-seo` | Write | Writes one or more Yoast fields; omitted fields are untouched. Idempotent | post_id | title, meta_description, focus_keyphrase, canonical, robots_noindex, robots_nofollow, opengraph_title, opengraph_description, twitter_title, twitter_description, breadcrumbs_title | the same fields, re-read | readonly=false, destructive=true, idempotent=true | `edit_posts` + `edit_post` (post) |

#### Contact Form 7 (2) — `forms`

Uses `WPCF7_ContactForm::find()`, `::get_instance()` and `scan_form_tags()`.

**Excluded:** mail templates and their recipient, sender, CC/BCC and reply-to
addresses; submissions and submission add-on data; reCAPTCHA, Turnstile,
Akismet and other integration keys.

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/contact-form-7-list-forms` | Read | Forms with bounded pagination | None | page, per_page | forms[] (id, title, shortcode, locale), total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | `wpcf7_read_contact_forms` (or `manage_options`) |
| `wp-mcp/contact-form-7-get-form` | Read | One form plus its field structure | form_id | None | id, title, shortcode, locale, fields[] (name, type, required) | readonly=true, destructive=false, idempotent=true | `wpcf7_read_contact_forms` (or `manage_options`) |

#### Gravity Forms (2) — `forms`

Uses `GFAPI::get_forms()`, `GFAPI::get_form()` and `GFAPI::count_entries()`.
Entry *counts* are aggregates and are returned; entries themselves are not.
Gravity Forms offers no server-side paging for forms, so this domain's bounded
pagination is applied in PHP after the call — form counts are small by nature,
and entry volume, which is not, never leaves the plugin. Its own
`GFCommon::current_user_can_any()` decides access when it is loaded, so the
`gform_full_access` super-capability keeps working.

**Excluded:** entries and everything in them, including uploads and submitter
IPs; notifications and confirmations and their recipient addresses; feeds and
add-on settings, including payment gateway and CRM credentials.

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/gravity-forms-list-forms` | Read | Forms with bounded pagination and aggregate entry counts | None | active, page, per_page | forms[] (id, title, is_active, is_trash, date_created, entry_count), total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | `gravityforms_edit_forms` (or `manage_options`) |
| `wp-mcp/gravity-forms-get-form` | Read | One form plus its field structure | form_id | None | the list fields plus fields[] (id, label, type, required) | readonly=true, destructive=false, idempotent=true | `gravityforms_edit_forms` (or `manage_options`) |

#### WPForms (2) — `forms`

WPForms stores each form as one post of its own registered `wpforms` post
type, whose `post_content` is the form's JSON definition; this adapter reads
exactly that, through core's `WP_Query` and `json_decode()`, and returns the
enumerated subset below. WPForms Lite ships no PHP form-reading API that is
stable across Lite and Pro, so this is the honest route rather than reaching
into `wpforms()` internals.

**Excluded:** entries (WPForms Pro); the `settings` block — notification and
confirmation configuration, recipient addresses, and Mailchimp/Stripe-style
integration connections; field choices and default values, which routinely
carry pre-filled personal data on customer-facing forms.

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/wpforms-list-forms` | Read | Forms with bounded pagination | None | search, page, per_page | forms[] (id, title, status, date_created, field_count), total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | `wpforms_view_forms` (or `manage_options`) |
| `wp-mcp/wpforms-get-form` | Read | One form plus its field structure | form_id | None | the list fields plus fields[] (id, label, type, required) | readonly=true, destructive=false, idempotent=true | `wpforms_view_forms` (or `manage_options`) |

#### Detected but not covered yet (23)

Every one of these is reported by `wp-mcp/list-integrations` with
`covered: false` and an empty `abilities` list, and every one already records
its exclusions.

| Plugin | Group | Detection signal |
|---|---|---|
| Rank Math SEO | `seo` | `RANK_MATH_VERSION`, class `RankMath` |
| All in One SEO | `seo` | `AIOSEO_VERSION` |
| SEOPress | `seo` | `SEOPRESS_VERSION` |
| Fluent Forms | `forms` | `FLUENTFORM_VERSION` |
| Ninja Forms | `forms` | `NF_PLUGIN_VERSION`, class `Ninja_Forms` |
| The Events Calendar | `events` | `TRIBE_EVENTS_FILE`, class `Tribe__Events__Main` |
| Events Manager | `events` | `EM_VERSION` |
| MemberPress | `membership` | `MEPR_VERSION` |
| Paid Memberships Pro | `membership` | `PMPRO_VERSION` |
| Restrict Content Pro | `membership` | `RCP_PLUGIN_VERSION` |
| WP Mail SMTP | `mail` | `WPMS_PLUGIN_VER` |
| FluentSMTP | `mail` | `FLUENTMAIL_PLUGIN_VERSION` |
| Post SMTP | `mail` | `POST_SMTP_VER` |
| Wordfence Security | `security` | `WORDFENCE_VERSION` |
| Sucuri Security | `security` | `SUCURISCAN_VERSION` |
| Solid Security | `security` | class `ITSEC_Core` |
| WP Rocket | `cache` | `WP_ROCKET_VERSION` |
| W3 Total Cache | `cache` | `W3TC_VERSION` |
| WP Super Cache | `cache` | `WPCACHEHOME` |
| LiteSpeed Cache | `cache` | `LSCWP_V` |
| Site Kit by Google | `analytics` | `GOOGLESITEKIT_VERSION` |
| MonsterInsights | `analytics` | `MONSTERINSIGHTS_VERSION` |
| Matomo Analytics | `analytics` | `MATOMO_ANALYTICS_FILE` |

**Adding an integration touches no core file.** A new adapter is one class in
`includes/integrations/` plus one line in the `WP_MCP_Integrations`
manifest — the registry loads the file itself, and the permission-matrix rows
come from the adapter, so neither `class-ability-matrix.php` nor the bootstrap
changes.

### 4x. Global Discovery — Search, Registered Objects, Capabilities and Features (Issue #16)

Nine read-only abilities under the `wp-mcp-discovery` category: the layer an
agent consults *before* it mutates anything, so it plans against what this
installation actually has and what this caller may actually do, instead of
guessing. Implemented in `WP_MCP_Discovery` and registered by
`WP_MCP_Discovery_Abilities`.

**The conservative model is the architecture of this domain.** Every response
is made of normalised identifiers, fixed vocabularies, booleans and counts —
nothing else. A WordPress registry is an open surface: any plugin may register
anything under any key, so a title, a label, a description, a keyword, a
registration map, a default or enum value, a callback, a path, a URL, an option
name or any other free text that reached a registry is **not** reported,
whatever it says. Concretely:

- an identifier is emitted only when it matches an anchored, length-bounded
  grammar (`GRAMMAR_KEY`, `GRAMMAR_BLOCK`, `GRAMMAR_ATTRIBUTE`, `GRAMMAR_MIME`,
  `GRAMMAR_EXTENSION`, `GRAMMAR_THEME`, `GRAMMAR_DATETIME`), and is dropped
  whole otherwise;
- a field with a known vocabulary is checked against its allowlist at runtime
  **and** declared as an `enum` in the MCP output schema;
- every collection has an explicit ceiling (`MAX_COLLECTION`, 200) and the
  response says `capped` when it hits it;
- nothing is ever cast, so an object or a closure sitting in a registry cannot
  reach `strval()` and take discovery down with it.

The trade-off is deliberate and worth stating: global search answers with IDs,
types and statuses rather than titles, so an agent locates objects here and
reads them through `get-post` / `get-media` / `get-custom-post`, which are
capability-checked per object.

**Discovery is not an explorer/runner.** There is no REST-route lister, no
route executor, no `describe( object, args )` dispatcher and no ability that
accepts a route, an endpoint, an option or transient name, a callback or
function name, a filesystem path or a SQL fragment — epic #1 forbids all of
them, and a test asserts no discovery input is named after any of them.
Registration data is described, never dereferenced: a block type reports
`is_dynamic` as a boolean and never its `render_callback`, an attribute
reports whether it *has* a default and never the value, and theme feature
support is a boolean per feature and never `get_theme_support()`'s registered
arguments — which for `custom-header` and `custom-background` contain PHP
callbacks and asset paths.

**`supports` is normalised, not forwarded.** `WP_Block_Type::$supports` is an
arbitrary registration array: a block — core, third-party or hostile — may park
a callable, a filesystem path, an API key or an entire plugin configuration
under any key it likes. So the raw array never leaves the site. Only the
feature names in `WP_MCP_Discovery::block_support_vocabulary()` are described,
each as `{ feature, enabled, sub_features }` where `sub_features` are names
from a second fixed allowlist; a key outside the vocabulary is dropped whole,
name and value alike, and a registered *value* is never emitted — a closure or
object under an allowlisted key is reported as `enabled: false` and never
inspected. `supports_keys` in the list response is the same allowlisted
subset. An adversarial fixture block registered with a path, a key, a webhook,
a closure and a nested config proves none of it appears in either response.

**The same rule covers block context, attributes and the summary lists.**
`uses_context` and `provides_context` are reported as two lists of context
*names* from `block_context_vocabulary()` — never as the registered map,
whose keys and values are both arbitrary. An attribute is reported as
`{ name, types, source, has_default, has_enum }`: `types` are JSON Schema type
names, `source` is a block-editor source name, and the `default` and `enum`
*values* become booleans, because both are unbounded author data. An
attribute whose name is not a plain identifier is dropped with it.
`keywords`, `parent` and `ancestor` are filtered by type and shape rather
than cast: a closure or a non-stringable object in one of those lists is
skipped, never passed to `strval()`, which would have taken discovery down
with a fatal instead of describing the block.

**Global search resolves visibility per object.** The query is narrowed first
— public statuses across every searchable post type, non-public ones only
across the types this caller may edit, with WP_Query's own `editable` clause
restricting them to the caller's own content when they lack
`edit_others_posts` — and then every surviving row is re-checked with the
native `read_post` meta-capability, which is the gate that actually decides.
The two layers are not redundant: a *private* post of another author survives
the query clause and is stopped by `read_post`, and there is a test for
exactly that. Users are searched only with `list_users`, only across
`user_login` / `user_nicename` / `display_name`, and never by e-mail address;
a caller without that capability gets the user branch reported in `skipped`
rather than silently dropped. Every branch asks its backend for one row more
than it will return, so `capped` is true as soon as *any* branch truncated the
match — not only when the merged ceiling is reached. While `capped` is true,
`total` and `total_pages` are lower bounds and the last page does not mean
"that was everything"; narrowing `search` or `types` is what completes the
picture. Results carry identity and reachability
(`object_type`, `id`, `subtype`, `title`, `slug`, `status`, `url`, `date_gmt`,
`editable`) and never a body: `get-post`, `get-media` and `get-custom-post`
remain the only capability-checked route to content. The internal post types
that belong to another domain — `wp_block`, `wp_template`, `wp_template_part`,
`wp_navigation`, `wp_global_styles`, revisions, menu items — are excluded
outright, and attachments are searched in their own branch.

**Already covered elsewhere, deliberately not duplicated here:** post types
and taxonomies (§4k, §4t), image sizes and Site Health (§4u), menu locations
and widget areas (§4n, §4o), roles and per-user capabilities (§4m), available
update targets and plugin/theme metadata (§4p–§4r). Issue #16 adds only what
was missing, and points at the existing abilities for the rest.

| Name | Type | Description | Required Inputs | Optional Inputs | Output Fields | Annotations | Required Capability |
|------|------|-------------|-----------------|-----------------|---------------|-------------|-------------------|
| `wp-mcp/global-search` | Read | Capability-filtered search across posts, pages, custom post types, media, terms and users. Identity and reachability only — no title, no slug, no permalink. `capped` is true whenever a branch or the merge truncated the match, and then `total`/`total_pages` are lower bounds | search (2-200 chars) | types (post/media/term/user), page, per_page (max 50) | results[] (object_type enum, id, subtype slug, status slug, date_gmt, editable), searched (enum), skipped[] (type enum, reason enum), capped, total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | `read` + per-object `read_post`; `list_users` for the user branch |
| `wp-mcp/list-post-statuses` | Read | Every registered post status as a name plus its visibility flags; the registered label is free text and is not reported | None | None | statuses[] (name slug, public, internal, protected, private, exclude_from_search, show_in_admin_all_list, show_in_admin_status_list), total, capped | readonly=true, destructive=false, idempotent=true | `read` |
| `wp-mcp/list-mime-types` | Read | The mime types and extensions WordPress will accept *from this caller*, plus the maximum upload size | None | None | mime_types[] (mime_type, extensions[]), total, capped, max_upload_size_bytes | readonly=true, destructive=false, idempotent=true | `upload_files` |
| `wp-mcp/list-block-types` | Read | Registered block types as identifiers: name, category slug, parent/ancestor block names, attribute names and allowlisted supports. Title, description and keywords are free text and are not reported. The registry scan budget is spent on entries *examined*, so `total` is a lower bound and `capped` is true as soon as the budget stopped the scan | None | block_namespace (validated against the `block_ns` grammar and refused, never rewritten), page (max 1000), per_page (max 50) | block_types[] (name, category, is_dynamic, parent[], ancestor[], supports_keys[] enum, attribute_names[]), total, total_pages, page, per_page | readonly=true, destructive=false, idempotent=true | `edit_posts` |
| `wp-mcp/get-block-type` | Read | One block type through allowlisted vocabularies only — never the raw supports array, never the context map, never attribute defaults or enumerations, never the render callback | block_type (e.g. core/paragraph) | None | name, category, is_dynamic, parent[], ancestor[], supports_keys[], attribute_names[], api_version, supports[] (feature enum, enabled, sub_features[] enum), attributes[] (name, types[] enum, source enum, has_default, has_enum), uses_context[] enum, provides_context[] enum | readonly=true, destructive=false, idempotent=true | `edit_posts` |
| `wp-mcp/list-pattern-categories` | Read | The *names* of the registered block pattern categories; labels and descriptions are free text and are not reported | None | None | categories[] (name slugs), total, capped | readonly=true, destructive=false, idempotent=true | `edit_posts` |
| `wp-mcp/list-template-types` | Read | Block template type and template-part area slugs from the fixed core vocabularies, reported only where a Site Editor exists; a classic theme answers with empty lists and `reason: classic_theme` | None | None | block_theme, template_editing, reason (enum), template_types[] (enum), template_part_areas[] (enum), total_template_types, total_template_part_areas | readonly=true, destructive=false, idempotent=true | `edit_theme_options` |
| `wp-mcp/get-current-user-capabilities` | Read | The caller's own effective roles and granted capabilities — what `current_user_can()` actually answers | None | None | user_id, roles[], capabilities[], total, capped, is_super_admin, multisite | readonly=true, destructive=false, idempotent=true | authenticated (self only) |
| `wp-mcp/list-feature-support` | Read | Theme feature support from a fixed vocabulary, plus the installation capabilities an agent branches on | None | None | theme (stylesheet slug, template slug, is_block_theme, has_theme_json), features[] (feature enum, supported), site (multisite, block_editor, site_editor, pretty_permalinks, application_passwords, widgets_block_editor, nav_menu_locations, registered_post_types, registered_taxonomies), total | readonly=true, destructive=false, idempotent=true | `edit_posts` |

## 13. Testing

- Tests require WordPress PHPUnit test framework (wp-phpunit)
- Setup: `composer require --dev yoast/phpunit-polyfills` (or wp-env)
- Scaffold: `wp scaffold plugin-tests wordpress-mcp-abilities --force --path=/ruta/a/wordpress`
- Run from the scaffolded plugin directory: `WP_TESTS_DIR=/ruta/a/wordpress-tests-lib phpunit --configuration phpunit.xml.dist`
- Multisite suite: `composer run test:multisite` (`phpunit --configuration phpunit.multisite.xml.dist`). It defines `WP_TESTS_MULTISITE`, so the WordPress test framework installs a network, and it is scoped to `tests/test-network.php` — the rest of the suite describes single-site behaviour (role capabilities, `unfiltered_html`, user deletion) that WordPress itself defines differently on a network, so running it there would report environment differences as regressions. Under the ordinary single-site configuration every test in `test-network.php` skips itself. CI runs it as the `unit-multisite` job.
- `tests/test-network.php` covers the multisite half of Issue #13: the Super Admin / site-administrator capability boundary on every domain; site creation from a slug with a full domain, a path, a URL and malformed slugs all refused, duplicate addresses and network illegal names refused; title/public/mature updates with `blog_public` kept in step with the `wp_blogs.public` column, and domain/path proven unmovable; archive, deactivate and spam round trips with their idempotent repeats; the main site and the current site refused for every disabling operation and for deletion; network user creation subject to core's own signup policy (banned email domains) and starting with no site membership; the add/set-role/remove membership cycle, role validation against the target site, idempotency for the same role and refusal of a conflicting one; content reassigned on removal, and network user deletion refusing a Super Admin and the current user, requiring a reassignment user and proving the reassigned post survives; network plugin deactivation round trip plus traversal and uninstalled-plugin refusals; theme enable/disable round trip with the main site's active theme refused; and the network settings allowlist — round trip, unknown field, raw network option name (with the option proven untouched), read-only field, enum/integer/domain/filetype validation and normalisation, and the plugins-menu toggle
- `tests/test-security.php` covers Issue #15's cross-cutting threat model in 48 test cases: registry-wide sweeps (every ability MCP-only and never REST-exposed, every input schema closed, no ability accepting a server-controlled or forbidden field, `post_type` only as the custom-post-type selector, bounded pagination on both halves of the contract, and the whole surface refusing an anonymous caller); the audit contract (every ability resolving to a known object type, every write ability to a non-empty one, the full field set on a real mutation, a failed mutation carrying its error code, credential-shaped keys dropped, long values truncated, base fields never overwritten); privilege escalation in users/roles (a `promote_users` holder unable to grant a role carrying capabilities it does not itself hold, through `change-user-role`, `add-user-role` and `create-user` alike, with administrators unaffected, and application-password listings never returning a credential); the post/page capability separation (`publish_pages`, `edit_others_pages` and a post-only role locked out of the page abilities); object and taxonomy confusion across domains; the autosave ownership boundary; the media gates (`upload_files` on replace, parent `edit_post` on detach); the SSRF payload table and host policy; the settings allowlist (raw option names refused, no writable field mapping to a never-writable option, read-only fields unwritable); indirect arbitrary execution (an arbitrary hook with a listener refused by `schedule-cron-event`, a core maintenance hook refused as soon as it carries caller-supplied arguments (which could only reach a third-party listener on the same name), the same hook with no arguments and an already-queued hook still accepted, a negative timestamp refused, and a WXR import refusing a serialized PHP object in post, comment or term metadata — nested included — while still accepting a serialized array); protected and unregistered post-meta keys; bulk-action maxima; malformed scalar input; strict positive-ID parsing; the trash/delete-permanently separation; and annotation self-consistency
- Baseline verificado de WordPress 6.9: [`docs/test-baseline-wordpress-6.9.md`](../docs/test-baseline-wordpress-6.9.md) — **desactualizado tras la Parte A de la estabilización; regenerar una vez que CI corra la suite real** (ver `tests/test-documentation.php`, que verifica en CI que este número de tests coincide con `ReflectionClass`).
- 477 test cases covering: functionality, blindaje, mutation restrictions, role caps, idempotency, fuzz/input hardening, meta-capability overrides, category/matrix consistency (bidirectional, plus annotation-field verification), full post/page lifecycle (trash/restore/delete-permanently), scheduling, status transitions, attribute changes (author/slug/sticky/password/page-attributes), revisions/autosave, duplication/bulk actions, media metadata/relationships/SSRF/upload/replacement/rollback/lifecycle/bulk controls, taxonomy discovery/custom-taxonomy CRUD, taxonomy-confusion checks, registered term metadata, native taxonomy capabilities, object assignment, bulk assignment limits, comment creation/replies, filters, privacy, edit_comment, moderation lifecycle, permanent deletion, bounded bulk moderation, user/profile permissions, role escalation protection, safe deletion, and application-password secret isolation, classic navigation menus/locations/items, wp_navigation entities, templates, template parts, synced patterns, theme context, Site Editor permission boundaries, the Issue #8 stabilization regressions (strict positive-ID parsing, `wp_block`/`wp_navigation` creation capability, block-content validation, pagination contract, global-styles round-trip), and the Issue #9 plugin/theme/core-update domain (capability gating, SSRF-safe install-from-url, path-traversal rejection, active-theme/active-plugin delete conflicts, activate/deactivate/switch idempotency, and repository-lookup-failure handling via `plugins_api`/`themes_api` filter fixtures with no real network access — WordPress core's own `Core_Upgrader` network internals, such as checksum verification, are out of scope for mocking), and the Issue #9 follow-up hardening (network-scoped guard applied identically to activate/deactivate, a falsy delete_theme() reported as a failure instead of deleted:true, audit events emitted on every install/update/delete failure path and carrying from_version/to_version, bulk and all-item update limits and input validation, auto-update toggles honouring wp_is_auto_update_enabled_for_type() and state-forcing filters, and the capability-scoped list-available-updates snapshot), and the Issue #11 custom post type / registered post metadata domain (post type discovery and operability reasons, refusal of every core built-in type, `map_meta_cap => true` and `map_meta_cap => false` fixtures with their own `capability_type` and a `create_posts` capability deliberately distinct from `edit_posts`, both directions of the capability boundary between core posts and a custom type, post-type confusion on IDs, absent `title`/`editor`/`excerpt`/`revisions`/`thumbnail` supports, taxonomy-term filtering, lifecycle idempotency, revision round-trip, featured-media set/remove, and registered metadata for string/integer/boolean/number/array/object values plus unregistered, `single => false`, non-`show_in_rest`, protected `_`-prefixed, and `auth_callback`-refused keys; plus the post-type-aware permission gates themselves, a trash implementation that permanently deletes instead of trashing (the `EMPTY_TRASH_DAYS = 0` configuration), and an idempotent rewrite of a value the key's own `sanitize_callback` normalizes), and the Issue #12 system/cron/maintenance/import-export/privacy domain (registration, category, matrix and closed-schema checks for all 31 abilities; an assertion that no input field is named `path`, `file`, `callback`, `function`, `sql`, `query`, `command`, `option`, `transient` or `url`; an assertion that no read response contains `ABSPATH` or `WP_CONTENT_DIR`, and that `get-environment-info` exposes no salt, key or credential; the cron denylist verified across its three families, plus refusal of a hook with no registered listener, of hook names with characters WordPress hook names do not use, of non-scalar or over-long argument lists, of an unregistered recurrence and of a timestamp more than a year out; `schedule-cron-event` idempotency on (hook, args) and the signature it reports matching the key WordPress actually stored; a run that fires the queued hook, dequeues it and reschedules the recurring occurrence; `run-due-cron-events` skipping denylisted and orphaned hooks with their reason while leaving future events alone; object-cache flush, expired-only transient cleanup and the three update site transients with an unrelated one left intact; the `update_core` gate and input contract of `set-maintenance-mode` asserted without ever writing the marker; a real WXR export with each of its filters validated; refusal to import without the official importer plugin, and refusal of a payload that is not WXR; a settings round-trip through the validated writers, with an unknown group and a raw WordPress option name both refused before anything is written; and the whole privacy flow — creation always pending, `confirm_key` never present in a response, processing refused for an unconfirmed request and for the other request type, real per-page paging of registered exporters and erasers, a malformed exporter reported instead of fatal, core's export-file step reached exactly once, and core marking the erasure request completed), and the Issue #13 network domain as a single-site installation sees it (all 30 abilities registered, catalogued in the matrix, closed-schema, MCP-only; no input field named after an option, a domain, a path or a URL; every network callback answering `wp_mcp_network_unsupported` with HTTP 501 both as an administrator and as an unprivileged user, so "unsupported" always beats "permission denied"; permission callbacks false off a network; `get-network-info` answering on a single site with `is_multisite: false`, neutral values, `current_user_is_super_admin: false`, and a response whose fields exactly match its closed output schema; and the network settings allowlist proven never to reach an option it declares as never writable), and the Issue #14 integration layer (every manifest entry producing exactly one adapter with a unique kebab-case slug and a group from the closed vocabulary; covered adapters declaring abilities, capabilities and exclusions, detect-only adapters declaring exclusions and no ability; every integration ability namespaced by its own adapter slug; the detection contract — a complete result shape, no claimed detection source when the plugin is absent, a constant signal found and its version reported through a throwaway adapter, since `detect()` is final and an adapter cannot fake its own availability; the acceptance criterion itself, that every integration ability documented in the matrix is *not* registered while its plugin is absent, and that `register_available_abilities()` asks an available adapter and skips both an unavailable one and a detect-only one; matrix rows carrying the `integration` key, the full permission shape and the same set the adapters declare; the two framework abilities registered, categorised, closed-schema and MCP-only; `list-integrations` reporting detection and coverage independently and filtering by group and by coverage; `get-integration` describing a covered and a detect-only adapter and refusing an unknown slug; `activate_plugins` required by both; and every covered adapter's callbacks answering `wp_mcp_integration_unavailable` rather than fataling when their plugin is not installed), and the Issue #16 discovery domain (all 9 abilities registered, catalogued, closed-schema, MCP-only and read-only; no input named after a route, an endpoint, an option, a transient, a callback, a path, a query or SQL, and no ability name shaped like a generic explorer or runner; post statuses with their visibility flags; `list-mime-types` refused without `upload_files`; block types paginated with `per_page` clamped to 50 and a namespace filter that leaks no other namespace; a dynamic fixture block whose detail exposes supports, context and the *shape* of its attributes but neither the render callback nor the stored defaults; malformed block names — `../../wp-config.php` included — refused as validation errors and an unregistered one as `wp_mcp_invalid_block_type`; pattern categories; template types and areas answering on a classic theme instead of failing; an adversarial block whose `supports`, block context, attributes, keywords, parent and ancestor registration parks paths, an API key, a webhook, callback identifiers, a closure, a non-stringable object and a nested plugin config, proving that both the list and the detail response report only allowlisted names and booleans, that `provides_context` never carries its map, that an attribute reports `types`/`source` from their vocabularies with `has_default`/`has_enum` instead of the values, that an attribute name which is not an identifier is dropped, and that none of it fatals; a classic-theme template surface answering with empty lists and `reason: classic_theme` while a hybrid and a block theme get the vocabulary; global search reporting `capped` as soon as a branch truncates (51 posts and 51 terms) and reporting it false with exact `total`/`total_pages` when the match fits; the current user's effective capabilities never reporting a denied one as granted; feature support proven to be booleans and counts, with a `custom-header` registered with an `admin-head-callback` and an image path proving neither reaches the response; and global search — a published post found, the caller's own draft found, another user's draft never returned, another user's *private* post refused for the agent and for a subscriber but returned to an administrator (the case that proves `read_post`, not the query clause, is the gate), users searched only with `list_users` and otherwise reported in `skipped`, media and terms covered, post content and e-mail addresses absent from every response, `wp_block` excluded as another domain's object, bounded pagination, validated input and an anonymous caller refused; and the contract itself — every published `output_schema` being exactly what `WP_MCP_Discovery_Contract::output_schema()` generates, every array declaring `maxItems`, every string being a closed `enum` or an anchored `pattern` plus `maxLength`, every integer a bounded count or an `enum`, every object closed, the runtime vocabularies being the contract vocabularies, and every grammar rejecting paths, URLs, free text, a trailing newline and anything past its length bound in its PHP form as well as its JSON Schema one, with all nine real responses validated field by field against their own published schema; plus oversized but entirely legitimate registries — a block registry past the scan ceiling answering `capped` with `total` as a lower bound and, through a `WP_Block_Type` that counts how often it was inspected, proving a summary is built only for the requested page; one block whose attributes, parents and ancestors all overflow cut at their ceilings and say `capped`; the same for post statuses, pattern categories, the mime map (including the extensions merged per type) and the caller’s capabilities; and `api_version` reported only when it belongs to the block editor’s vocabulary and 0 otherwise), and the post-review hardening round (every numeric descriptor publishing a finite, JSON-safe `maximum` and every integer in every real response validated against it; `bounded_count()` clamping and flagging `INF`, `NAN`, `PHP_INT_MAX`, `1.0e300`, negatives, strings and objects; `bounded_id()` returning 0 and flagging — never clamping — a digit string wider than the window, `1.5`, `1e3`, ` 12` or `-12`, while accepting the digit-string shape `$wpdb` answers with; one oversized MIME extension group in a single-entry map reporting `capped`, so nothing else in the response can be what set it; a summary truncated inside `format_block_summary()` propagating `capped` to the listing; the scan budget spent on entries that do not match the namespace and on names outside the grammar — `0wp-mcp-bulk/…` blocks, legal for core's registry and illegal for this one — proven by `capped` with not one `WP_Block_Type` inspected; `block_namespace` refusing `Core`, `CORE`, `core/paragraph`, `core_plugin`, `../../wp-config.php`, a URL, a space, `_core`, `9core`, `-core`, a trailing newline, a leading space, an over-long value, an array and an integer, and publishing exactly the `pattern` and `maxLength` it enforces; and `page` above `max_page` refused by both paginated abilities, with the same ceiling published on the way in and on the way out)

## 14. Audit Logging
- Write operations fire `wp_mcp_audit_log` action hook
- Enable error_log output: define `WP_MCP_AUDIT_LOG` as true in `wp-config.php`
- Fields logged: timestamp, user_id, ability, `object_type`, object_id, result, error_code — the fixed event shape issue #15 requires
- `object_type` comes from a closed vocabulary (`post`, `page`, `attachment`, `term`, `user`, `plugin`, `theme`, `cron_event`, …) resolved from the ability name, so a new domain cannot introduce an unaudited object kind; the custom-post-type domain overrides it with the real post type slug
- Never logs: content, passwords, tokens, headers, cookies, secrets. `WP_MCP_Audit::scrub_context()` enforces this structurally: it drops every context key whose name contains `password`, `token`, `secret`, `credential`, `cookie`, `nonce`, `salt`, `*_key`, `authorization`, `content` or `body`, keeps only scalars, truncates every string value to 120 characters, and never lets a context key overwrite a base field
- Extend by hooking into the `wp_mcp_audit_log` action
- `bulk-trash-posts`, `bulk-trash-media`, and `bulk-moderate-comments` log an aggregate entry (`object_id=0`) and one entry per item (via the corresponding singular ability name)

## 15. Update Procedure
1. Backup your site
2. Deactivate the plugin (data is preserved)
3. Upload new version
4. Activate
5. The role is automatically reconciled on `admin_init`

## 16. Rollback Procedure
1. Deactivate current version
2. Delete plugin files
3. Upload previous version
4. Activate
5. Role reconciliation runs automatically

## 17. Uninstallation
- Deactivation preserves the role and all data
- Full uninstall (delete plugin): removes the role ONLY if no users are assigned to it
- If users exist with the role, admin must reassign them manually first
- To manually remove the role after reassigning users: delete and reinstall the plugin, or use WP-CLI: `wp role delete wp_mcp_agent`

## 18. Error Codes
- `wp_mcp_permission_denied` - User lacks the general or meta-capability required, but is the object's own author (e.g. a Contributor without `edit_published_posts`)
- `wp_mcp_ownership_violation` - Current user is not the object's author and lacks the capability (e.g. `edit_others_posts`, `delete_posts`) needed to act on someone else's content — derived from the native meta-capability check, not from a hardcoded author comparison
- `wp_mcp_invalid_post` - Post does not exist
- `wp_mcp_invalid_page` - Page does not exist
- `wp_mcp_invalid_media` - Attachment does not exist
- `wp_mcp_not_an_image` - Attachment is not an image
- `wp_mcp_unsupported_post_type` - Wrong post type for the ability
- `wp_mcp_invalid_taxonomy_term` - Term doesn't exist or wrong taxonomy
- `wp_mcp_validation_error` - Input validation failed
- `wp_mcp_create_failed` - Post creation failed
- `wp_mcp_update_failed` - Post, page, or attribute update failed
- `wp_mcp_publish_failed` - Post or page publish failed
- `wp_mcp_invalid_revision` - The specified revision does not exist for this post/page
- `wp_mcp_invalid_status_transition` - The requested status change isn't allowed from the object's current status
- `wp_mcp_bulk_limit_exceeded` - A bulk request exceeded the maximum item count
- `wp_mcp_invalid_template` - The specified page template doesn't exist in the active theme
- `wp_mcp_trash_failed` - Moving a post/page to trash failed
- `wp_mcp_restore_failed` - Restoring a post/page from trash failed
- `wp_mcp_delete_failed` - Permanently deleting a post/page failed
- `wp_mcp_upload_failed` - Client or remote media upload failed
- `wp_mcp_upload_too_large` - Decoded upload exceeds the 10 MiB limit
- `wp_mcp_unsupported_media_type` - File MIME type or extension is not allowed by WordPress
- `wp_mcp_download_failed` - Remote media download failed
- `wp_mcp_remote_url_denied` - URL rejected by the HTTP(S), host, or SSRF policy
- `wp_mcp_replace_failed` - Attachment replacement failed
- `wp_mcp_attach_failed` - Attachment relationship update failed
- `wp_mcp_detach_failed` - Attachment relationship removal failed
- `wp_mcp_media_file_missing` - Attachment file is missing or unreadable
- `wp_mcp_invalid_taxonomy` - Taxonomy is not registered
- `wp_mcp_invalid_term` - Term does not exist in the requested taxonomy
- `wp_mcp_taxonomy_validation_error` - Taxonomy, hierarchy, or term input is invalid
- `wp_mcp_taxonomy_permission_denied` - Current user lacks the taxonomy capability required
- `wp_mcp_taxonomy_object_type_mismatch` - Object type/ID is not registered for the taxonomy
- `wp_mcp_term_query_failed` - Term query failed
- `wp_mcp_term_create_failed` - Term creation failed
- `wp_mcp_term_update_failed` - Term update failed
- `wp_mcp_term_delete_failed` - Term deletion failed
- `wp_mcp_term_assignment_failed` - Object-term relationship update failed
- `wp_mcp_term_meta_not_registered` - Term metadata key is not registered and REST-visible
- `wp_mcp_term_meta_update_failed` - Term metadata update failed
- `wp_mcp_invalid_comment` - Comment does not exist
- `wp_mcp_comment_permission_denied` - Current user lacks the required comment or related post capability
- `wp_mcp_comment_validation_error` - Comment input, status, parent, or date filter is invalid
- `wp_mcp_comment_trash_unavailable` - Comment trash is disabled; permanent deletion was refused
- `wp_mcp_comment_query_failed` - Comment query failed
- `wp_mcp_comment_create_failed` - Comment creation failed
- `wp_mcp_comment_update_failed` - Comment update failed
- `wp_mcp_comment_moderation_failed` - Comment status transition failed
- `wp_mcp_comment_delete_failed` - Permanent comment deletion failed
- `wp_mcp_invalid_user` - User does not exist
- `wp_mcp_user_permission_denied` - User operation is outside the native permission boundary
- `wp_mcp_user_validation_error` - User input is invalid or incomplete
- `wp_mcp_user_create_failed` / `wp_mcp_user_update_failed` / `wp_mcp_user_delete_failed` - User mutation failed
- `wp_mcp_user_delete_unsupported` - User deletion is not exposed for multisite
- `wp_mcp_user_self_promotion_denied` - Role change would remove current-user promotion authority
- `wp_mcp_role_permission_denied` / `wp_mcp_role_validation_error` / `wp_mcp_role_not_found` - Role operation failure
- `wp_mcp_role_protected` - The plugin-managed `wp_mcp_agent` role cannot be changed through role abilities
- `wp_mcp_role_in_use` / `wp_mcp_role_create_failed` / `wp_mcp_role_delete_failed` - Role lifecycle failure
- `wp_mcp_application_password_validation_error` - Application-password input is invalid
- `wp_mcp_application_password_unavailable` - Application Passwords are unavailable for site or user
- `wp_mcp_application_password_create_failed` / `wp_mcp_application_password_not_found` / `wp_mcp_application_password_revoke_failed` - Application-password operation failed
- `wp_mcp_navigation_permission_denied` / `wp_mcp_navigation_validation_error` - Navigation or Site Editor permission/input failure
- `wp_mcp_invalid_menu` / `wp_mcp_invalid_menu_item` / `wp_mcp_invalid_menu_location` - Navigation object or registered location does not exist
- `wp_mcp_navigation_create_failed` / `wp_mcp_navigation_update_failed` / `wp_mcp_navigation_delete_failed` - Classic navigation mutation failed
- `wp_mcp_site_editor_unsupported` - Active WordPress installation or theme does not support the requested Site Editor entity
- `wp_mcp_invalid_pattern` / `wp_mcp_invalid_navigation` - Block pattern or `wp_navigation` entity does not exist
- `wp_mcp_site_editor_validation_error` / `wp_mcp_site_editor_create_failed` / `wp_mcp_site_editor_update_failed` / `wp_mcp_site_editor_delete_failed` - Site Editor entity validation or mutation failed
- `wp_mcp_invalid_plugin` - Plugin is not installed
- `wp_mcp_plugin_permission_denied` / `wp_mcp_plugin_validation_error` - Plugin operation permission or input failure
- `wp_mcp_plugin_active_conflict` - Refused to delete a plugin that is still active
- `wp_mcp_plugin_install_failed` / `wp_mcp_plugin_update_failed` / `wp_mcp_plugin_delete_failed` / `wp_mcp_plugin_activate_failed` - Plugin lifecycle operation failed
- `wp_mcp_plugin_auto_update_unavailable` - Plugin auto-updates are disabled for this installation, or a filter forces this plugin's state
- `wp_mcp_invalid_theme` - Theme is not installed
- `wp_mcp_theme_permission_denied` / `wp_mcp_theme_validation_error` - Theme operation permission or input failure
- `wp_mcp_theme_active_conflict` - Refused to delete the currently active theme
- `wp_mcp_theme_install_failed` / `wp_mcp_theme_update_failed` / `wp_mcp_theme_delete_failed` / `wp_mcp_theme_switch_failed` - Theme lifecycle operation failed
- `wp_mcp_theme_auto_update_unavailable` - Theme auto-updates are disabled for this installation, or a filter forces this theme's state
- `wp_mcp_core_update_unavailable` - No WordPress core update is currently available
- `wp_mcp_core_update_failed` - WordPress core update failed
- `wp_mcp_translation_updates_unavailable` - No translation (language pack) updates are currently available
- `wp_mcp_translation_update_failed` - Translation package installation failed
- `wp_mcp_no_updates_pending` - No installed item currently has an update available
- `wp_mcp_settings_unknown_field` - The requested field is not in the settings allowlist; raw WordPress option names are never accepted
- `wp_mcp_settings_readonly_field` - The field exists but is exposed for reading only
- `wp_mcp_settings_permission_denied` - Current user lacks `manage_options` (or `manage_privacy_options` for the privacy group)
- `wp_mcp_settings_validation_error` - Type, range, enum, or cross-field invariant violated
- `wp_mcp_settings_unsupported` - The setting is network-managed on multisite and not writable per site
- `wp_mcp_settings_update_failed` - Persisting the setting failed
- `wp_mcp_invalid_post_type` - The requested post type is not registered
- `wp_mcp_post_type_not_operable` - The post type is registered but outside this domain: a core built-in, a type owned by another WordPress MCP domain, or one that never opted into `show_in_rest`
- `wp_mcp_post_type_unsupported_feature` - The post type does not declare support for the feature the ability needs (`title`, `editor`, `excerpt`, `revisions`, `thumbnail`)
- `wp_mcp_post_type_permission_denied` - The current user lacks the capability *this post type* declares, resolved from its registration rather than from a literal post capability
- `wp_mcp_post_type_validation_error` - Custom post type input (field type, missing title, half a taxonomy filter, or a metadata value failing its registered schema) is invalid
- `wp_mcp_invalid_custom_post` - No entry with that ID exists in the requested post type; an ID belonging to another type gets the same answer and its existence elsewhere is never confirmed
- `wp_mcp_post_meta_not_registered` - The post metadata key is not registered for this post type (or globally), or is registered without `show_in_rest`
- `wp_mcp_post_meta_not_operable` - The key is registered and REST-visible but stored as `single => false`, so it has no single value to read, write, or delete
- `wp_mcp_post_meta_protected` - Protected metadata key (`_`-prefixed or `is_protected_meta()`); refused before the registry is consulted
- `wp_mcp_post_meta_update_failed` - Persisting the post metadata key failed
- `wp_mcp_system_permission_denied` - Current user lacks the capability the system, cron or maintenance operation requires
- `wp_mcp_system_validation_error` - System input invalid (for example a Site Health test name that is not a runnable direct core test)
- `wp_mcp_system_unsupported` - The system facility is unavailable on this installation (no Site Health class, no directory-size API, partial size result)
- `wp_mcp_cron_validation_error` - Cron input invalid: hook name, arguments, timestamp or recurrence
- `wp_mcp_cron_hook_denied` - The hook is on the permanent denylist, or has no registered listener on this site
- `wp_mcp_cron_event_not_found` - No scheduled event matches the requested hook and arguments
- `wp_mcp_cron_schedule_failed` / `wp_mcp_cron_unschedule_failed` - WordPress refused the schedule or unschedule
- `wp_mcp_maintenance_validation_error` - Maintenance input invalid (`enabled` missing or not a boolean)
- `wp_mcp_maintenance_unsupported` - The upgrader or the filesystem is unavailable for this request
- `wp_mcp_maintenance_failed` - The cache flush or maintenance marker write failed
- `wp_mcp_export_validation_error` - Export filter invalid: unknown post type, non-exportable type, unknown author, category, status, or a malformed date
- `wp_mcp_export_failed` - The exporter is unavailable or produced nothing
- `wp_mcp_export_too_large` - The export exceeds the response limit; narrow it with the filters
- `wp_mcp_import_validation_error` - The payload is empty, too large, not WXR, or fails the importer's own parser
- `wp_mcp_import_unsupported` - WordPress core ships no WXR importer and the official WordPress Importer plugin is not active
- `wp_mcp_import_failed` - Writing the temporary file or running the import failed
- `wp_mcp_privacy_permission_denied` - Current user lacks `export_others_personal_data` / `erase_others_personal_data`
- `wp_mcp_privacy_validation_error` - Privacy input invalid: missing or malformed email, bad request ID, unknown type or status filter
- `wp_mcp_privacy_request_not_found` - No personal data request with that ID (an ID belonging to another post type gets the same answer)
- `wp_mcp_privacy_request_invalid_state` - The request is not in a state that allows the operation: unconfirmed, or addressed through the other type's ability
- `wp_mcp_privacy_request_create_failed` / `wp_mcp_privacy_request_email_failed` - Creating the request, or sending its confirmation email, failed
- `wp_mcp_privacy_process_failed` - The export/erasure pipeline is unavailable, or a registered provider is malformed
- `wp_mcp_privacy_delete_failed` - Deleting the request record failed
- `wp_mcp_network_unsupported` - The installation is not a multisite network. The stable, HTTP 501 answer every network ability gives off a network, checked before any capability so a single site is never told it lacks a network capability
- `wp_mcp_network_permission_denied` - Current user lacks the concrete network capability the operation requires (`manage_sites`, `manage_network_users`, `manage_network_plugins`, `manage_network_themes`, `manage_network_options`, `upgrade_network`, `create_sites`, `delete_sites`). Being an administrator of a site never implies Super Admin
- `wp_mcp_network_validation_error` - Network input invalid: a malformed slug, a role that does not exist on the target site, a missing reassignment user, a self-targeted removal or deletion
- `wp_mcp_invalid_network_site` - No site with that ID on the current network (an ID belonging to another network gets the same answer)
- `wp_mcp_network_conflict` - The target exists but the operation is refused for a structural reason: the main site or the current site, a Super Admin as a deletion target, a duplicate site address, a network illegal name, a membership that already exists with a different role, a theme still active on the main site
- `wp_mcp_network_site_create_failed` / `wp_mcp_network_site_update_failed` / `wp_mcp_network_site_delete_failed` - WordPress refused the site create, update or delete
- `wp_mcp_network_user_create_failed` / `wp_mcp_network_user_delete_failed` - WordPress refused the network user create or delete
- `wp_mcp_network_update_failed` - The network database upgrade could not run
- `wp_mcp_integration_unknown` - No integration with that slug exists in the adapter manifest
- `wp_mcp_integration_unavailable` - The integration exists but its plugin is not available on this site. HTTP 501, checked before the capability so an agent is never told it lacks a third-party capability on a site where that plugin is not installed
- `wp_mcp_integration_object_not_found` - The object addressed inside the integrated plugin (a product, an order, a form) does not exist
- `wp_mcp_integration_failed` - The integrated plugin refused the operation or answered with something unusable
- `wp_mcp_discovery_validation_error` - A discovery input is malformed or outside its documented vocabulary: a search phrase shorter than 2 or longer than 200 characters, a `types` entry that is not one of post/media/term/user, or a `block_type` that is not a `namespace/name` block name
- `wp_mcp_discovery_unsupported` - This WordPress installation does not expose the registry the ability describes (no block type or pattern category registry). HTTP 501
- `wp_mcp_invalid_block_type` - The requested block type is not registered on this site

## 19. Not Implemented Yet
generic option read/write (site **or** network), generic post/user/term/site/network metadata access,
filesystem, database, SQL, PHP execution, shell, WP-CLI, and — for every
third-party plugin — a generic function, REST-route, action/filter, option or
metadata bridge of any kind

Site settings are covered as of issue #10, but only through the explicit
per-field allowlist documented in §4s — never as generic option access,
which stays permanently out of scope (see §7). Custom post types and their
post metadata are covered as of issue #11, but only through the fixed
20-ability surface documented in §4t: registered, REST-visible,
single-valued, non-protected keys on post objects, never a generic metadata
accessor and never user/term/site/network metadata. Site Health, cron,
cache/maintenance, content and settings import/export and the personal data
privacy workflows are covered as of issue #12, but only through the 31 fixed
operations documented in §4u: no ability there accepts a path, a filename, a
URL, a callback or function name, an option or transient name, a cache group,
or a SQL fragment.

Third-party plugins are covered as of issue #14, but only through explicit
adapters: 15 fixed operations across five plugins, documented in §4w, each
one calling that plugin's own public API. Detection alone is available for
23 more plugins, which contribute no ability at all until a follow-up issue
decides — against a real production inventory — which of their operations
deserve one. What stays permanently out of scope for every integration,
covered or not, is any generic bridge into it.

Global discovery is covered as of issue #16, but only through the nine
read-only operations documented in §4x. There is no REST-route lister or
executor, no generic `describe( object, args )` dispatcher, and no discovery
input naming a route, an endpoint, an option or transient, a callback, a path
or a query. Global search reports identity and reachability, never content,
and never widens what a caller may see: it is the same `read_post` /
`list_users` gate the domain abilities use, applied per object.

Multisite network administration is covered as of issue #13, but only
through the 30 fixed operations documented in §4v: no ability there accepts a
domain, a path, a URL or a network option name, and every one of them checks
a concrete network capability rather than assuming Super Admin. On a
single-site installation they are registered and catalogued but never
offered, and answer `wp_mcp_network_unsupported`.

Three things issue #13 deliberately did **not** implement, each for a stated
reason rather than an oversight:

- **Granting or revoking Super Admin.** `grant_super_admin()` /
  `revoke_super_admin()` are the one operation whose blast radius is the whole
  network in a single call. It stays a human decision made in Network Admin,
  and a Super Admin is refused as a deletion target so this domain can never
  remove the people who could undo its own mistakes.
- **Moving a site to another domain or path.** That is a migration — DNS,
  cookies, hard-coded URLs in content — not a metadata edit, and a client that
  could name the domain could point a site at a host of its choosing.
- **A second install/update/delete surface for network plugins and themes.**
  WordPress already restricts `install_plugins` / `update_plugins` /
  `delete_plugins` and their theme counterparts to Super Admins on a network,
  so the issue #9 abilities already *are* the network-level operations; a
  near-identical second surface would be a weaker duplicate of a gate that
  already works.

Two things issue #12 deliberately did **not** implement, each for a stated
reason rather than an oversight:

- **Database repair/optimize.** WordPress ships no capability-safe API for it:
  `wp-admin/maint/repair.php` is a standalone screen gated on the
  `WP_ALLOW_REPAIR` constant, not a function. Every other route would mean
  running SQL composed by or supplied to an ability, which stays permanently
  out of scope (see §7).
- **A WXR parser of our own.** WordPress core has no importer; the parser lives
  in the official WordPress Importer plugin. `wp-mcp/import-content` requires
  that plugin and refuses cleanly without it, rather than growing a
  reimplementation of a third-party XML parser inside a security layer.

The remaining domains are tracked in epic #1's roadmap (issues #14-#16). Term merge/move is intentionally not exposed yet because
WordPress has no single official, capability-safe API for a generic merge
operation; explicit parent changes remain available for hierarchical terms.
Regardless of which domain they land in, none of them will ever take the
shape of arbitrary PHP/SQL execution, arbitrary option read/write, or
generic filesystem access (see §7).
