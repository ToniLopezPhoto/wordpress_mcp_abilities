# Issue #15 coverage report — MCP Adapter E2E and functional coverage vs wp-admin

This report covers two Issue #15 acceptance items: real MCP end-to-end tests
against `WordPress/mcp-adapter`, and the functional-coverage report against
wp-admin. Every statement below is either backed by a named deterministic test
or recorded as an explicit exclusion or pending item with its reason.

Adapter under test: `WordPress/mcp-adapter` at `MCP_ADAPTER_REF: v0.6.1`
(`.github/workflows/ci.yml`), the exact ref CI checks out.

## 1. Finding: the previous harness never reached the adapter

| Evidence | Location |
| --- | --- |
| CI checks the adapter out but only runs Composer at the repository root. | `.github/workflows/ci.yml`, `unit` job: `Checkout WordPress/mcp-adapter` step, then `composer update` without `--working-dir` |
| The v0.6.1 tag ships no `vendor/` directory. | GitHub tree of `WordPress/mcp-adapter@v0.6.1` (root: `includes/`, `tests/`, `composer.json`, `composer.lock`, …; no `vendor/`) |
| Without `vendor/autoload_packages.php`, the adapter stops before booting. | adapter `mcp-adapter.php` lines 48–53 (`if ( ! Autoloader::autoload() ) { return; }`); `includes/Autoloader.php` lines 44–45 and 56–59 |
| Its classes (`WP\MCP\*`) and its `wordpress/php-mcp-schema` dependency are only reachable through Composer. | adapter `composer.json` (`autoload.psr-4`, `require`) |
| The test bootstrap required `mcp-adapter.php` silently, and no test referenced `WP\MCP`, `tools/list` or the `mcp/mcp-adapter-default-server` route. | `wordpress-mcp-abilities/tests/bootstrap.php` (pre-change `wp_mcp_tests_maybe_load_mcp_adapter()`); repository-wide search |

So the domain suites (`test-abilities.php`, `test-security.php`,
`test-network.php`) prove in-process registration and
`wp_get_ability()->execute()` behaviour. They do not prove any MCP Adapter
flow.

## 2. What now exercises the adapter

- **Harness:** `tests/bootstrap.php` loads the adapter's Composer autoloader
  when it is present, then `mcp-adapter.php`, exactly as the adapter's own
  `tests/phpunit/bootstrap.php` does. It initialises the adapter on `init`
  (priority 20), which is the adapter's own WP-CLI branch in
  `McpAdapter::instance()`. `WP_MCP_REQUIRE_MCP_ADAPTER=1` makes a missing
  adapter a hard bootstrap failure rather than a skipped suite.
- **CI job `e2e-adapter`:** checks out the pinned ref, runs
  `composer install --no-dev --working-dir=mcp-adapter` (from the adapter's
  `composer.lock`), installs the WordPress 6.9 test suite and runs
  `composer run test:e2e` (`phpunit.e2e.xml.dist`).
- **Suite:** `wordpress-mcp-abilities/tests/e2e-mcp-adapter.php`. It sends
  real JSON-RPC bodies through `WP_REST_Server::dispatch()` to
  `/mcp/mcp-adapter-default-server`, applies `rest_post_dispatch` the way
  `serve_request()` does, and reads every response after a JSON round trip.

| Issue #15 E2E step | Covered by (in `tests/e2e-mcp-adapter.php`) | Status |
| --- | --- | --- |
| 1. `initialize` | `test_initialize_negotiates_the_protocol_and_opens_a_session` (protocol `2025-11-25`, server name, `Mcp-Session-Id`, then `notifications/initialized` → HTTP 202) | Covered |
| — transport and session gates | `test_default_server_boots_with_its_rest_route`, `test_an_anonymous_caller_never_reaches_the_transport` (HTTP 401, no session), `test_a_request_without_a_session_is_rejected` | Covered |
| 2. `tools/list` | `test_tools_list_exposes_exactly_the_default_servers_three_tools` | Covered |
| 3. discover abilities | `test_discovery_returns_exactly_the_registered_wp_mcp_abilities` (set equality with every registered `wp-mcp/*` ability) | Covered |
| 4. get ability info | `test_get_ability_info_publishes_every_registered_contract_unchanged` (every `wp-mcp/*` ability: input/output schema, `meta.mcp.public`, `show_in_rest`, annotations) | Covered |
| 5. execute read abilities | `test_execute_a_read_ability_returns_the_post_as_stored` (`wp-mcp/get-post`, compared with `get_post()`) | Covered (one read ability) |
| — capability and schema re-entry through `execute-ability` | `test_execute_reenters_the_abilitys_own_permission_callback` (subscriber cannot create a post), `test_execute_reenters_the_closed_input_schema` (smuggled `post_author` refused, nothing written) | Covered |
| 6. complete mutating workflows | The eleven `test_workflow_*` tests (see §3) | Covered |
| 7. validate final state in WordPress after those workflows | Every workflow step is checked through WordPress APIs (`get_post_status()`, `get_post_meta()`, `wp_get_object_terms()`, `wp_get_comment_status()`, `get_role()`, `get_plugins()`, …), never through the tool's own output | Covered |

Execution status: **not run yet.** The development machine has no PHP,
Composer or MySQL, so this suite, like every other PHPUnit suite, was not
executed locally. Its first real result is the `e2e-adapter` CI job.
Until that job is green, none of the "Covered" rows above are proven.

## 3. Mutating workflows through the adapter

Every step runs through the adapter's `mcp-adapter-execute-ability` tool
inside an MCP session opened with `initialize`. The resulting state is then
read from WordPress directly.

| Issue #15 workflow | Test | Steps through the adapter → state checked in WordPress |
| --- | --- | --- |
| Post lifecycle | `test_workflow_post_create_edit_publish_revision_trash_restore` | `wp-mcp/create-post` (draft, author = caller) → `wp-mcp/update-post` → `wp-mcp/publish-post` → `wp-mcp/list-post-revisions` (at least one revision exists) → `wp-mcp/trash-post` → `wp-mcp/restore-post`. Also checks that the publish emitted an audit event with the caller, the post ID and `success`. |
| Post scheduling | `test_workflow_post_schedule` | `wp-mcp/create-post` → `wp-mcp/schedule-post`: status `future`, a future GMT date, and `publish_future_post` queued for that post |
| Page | `test_workflow_page_create_edit_publish` | `wp-mcp/create-page` → `wp-mcp/update-page` → `wp-mcp/publish-page` |
| Media | `test_workflow_media_upload_metadata_featured_delete` | `wp-mcp/upload-media` (a real 1×1 PNG; file on disk, `image/png`) → `wp-mcp/update-media` (alt text and caption) → `wp-mcp/set-featured-image` → `wp-mcp/delete-media-permanently` (post gone, file gone, featured image cleared) |
| Terms | `test_workflow_term_create_edit_assign_delete` | `wp-mcp/create-term` → `wp-mcp/update-term` → `wp-mcp/assign-terms` → `wp-mcp/delete-term` (term gone, relationship gone) |
| Comment moderation | `test_workflow_comment_moderation` | `wp-mcp/approve-comment` → `wp-mcp/unapprove-comment` → `wp-mcp/mark-comment-spam` → `wp-mcp/unspam-comment` → `wp-mcp/trash-comment` → `wp-mcp/restore-comment` |
| Users and roles | `test_workflow_user_and_role_lifecycle` | `wp-mcp/create-role` → `wp-mcp/create-user` (with that role) → `wp-mcp/change-user-role` → `wp-mcp/delete-user` (content reassigned) → `wp-mcp/delete-role` |
| Navigation and Site Editor | `test_workflow_navigation_menu_and_synced_pattern` | `wp-mcp/create-nav-menu` → `wp-mcp/add-menu-item` → `wp-mcp/delete-nav-menu`; `wp-mcp/create-synced-pattern` → `wp-mcp/update-synced-pattern` → `wp-mcp/delete-synced-pattern` |
| Explicit settings | `test_workflow_explicit_settings` | `wp-mcp/update-general-settings` (`site_title` → `blogname`). The raw option name `blogname` is refused and the value stays unchanged. |
| Custom post type and registered meta | `test_workflow_custom_post_type_with_registered_meta` | A test-registered type with `show_in_rest` and one registered, single, string meta key: `wp-mcp/create-custom-post` → `wp-mcp/update-custom-post-meta` → `wp-mcp/publish-custom-post`. A protected (`_`-prefixed) key is refused and nothing is stored. |
| Fixture plugin | `test_workflow_fixture_plugin_install_activate_deactivate_update_delete` | `wp-mcp/install-plugin-from-repo` (version 1.0.0 on disk) → `wp-mcp/activate-plugin` → `wp-mcp/deactivate-plugin` → `wp-mcp/update-plugin` (version 2.0.0 on disk) → `wp-mcp/delete-plugin` (directory gone) |

How these tests stay deterministic, and where their scope stops:

- **The plugin workflow is offline.** Every outbound HTTP request is
  intercepted through `pre_http_request` and none leaves the process; all
  but the update checks below are refused. `plugins_api` answers for the
  fixture slug.
  `upgrader_pre_download`, which core's `WP_Upgrader::download_package()`
  consults before any download, returns a zip built locally with PclZip.
  The update is offered by setting the `update_plugins` site transient. The
  test then asserts that no package URL was ever requested over the network.
  Core's own post-upgrade update checks (`wp_version_check()`,
  `wp_update_plugins()`, `wp_update_themes()` on `upgrader_process_complete`)
  get a local HTTP 503 instead of a refusal: a refused request makes them
  raise `E_USER_WARNING` under `WP_DEBUG`, which fails the ability, while a
  503 is the unavailable-server answer they skip silently.
- **Plugin step order.** The update comes after the deactivation. Core's
  `Plugin_Upgrader::upgrade()` deactivates an active plugin before replacing
  it, so any other order would test upgrader internals rather than the
  abilities.
- **Scheduling and publishing use two separate posts.** One post is
  scheduled and a different one is published. The suite makes no claim about
  what `wp-mcp/publish-post` does to an already-scheduled post.
- **One action per group is not exhaustive.** Each group runs one complete
  lifecycle. The remaining abilities of each domain (for example bulk
  actions, `wp-mcp/install-plugin-from-url`, theme and core updates, network
  administration) are covered only by the in-process suites, not through the
  adapter.
- **Cleanup the database rollback does not cover:** the fixture plugin's
  directory and its upgrade backup, the in-memory role, and the registered
  meta key are removed in `tear_down()`.

Consequence: ZIP generation and the production-site web test are still not
the only remaining Issue #15 items. Two other items are open:

- a first green run of the `e2e-adapter` job, which is the only proof that
  anything in §2 and §3 holds;
- the local PHP lint and PHPUnit runs that were impossible on this machine.

## 4. Functional coverage vs wp-admin

The ability matrix (`WP_MCP_Ability_Matrix::get()`) is the source of truth.
The counts in the table below are checked against it by
`tests/test-documentation.php`
(`test_coverage_report_category_counts_match_matrix`). That check also
verifies that every `wp-mcp/*` name in this report is a real matrix entry
(`test_coverage_report_names_only_real_abilities`).

| Matrix category | Abilities |
| --- | ---: |
| `wp-mcp-content` | 58 |
| `wp-mcp-media` | 15 |
| `wp-mcp-taxonomies` | 16 |
| `wp-mcp-comments` | 13 |
| `wp-mcp-users` | 22 |
| `wp-mcp-navigation` | 11 |
| `wp-mcp-site-editor` | 22 |
| `wp-mcp-themes` | 10 |
| `wp-mcp-plugins` | 11 |
| `wp-mcp-system` | 25 |
| `wp-mcp-settings` | 18 |
| `wp-mcp-privacy` | 8 |
| `wp-mcp-network` | 30 |
| `wp-mcp-discovery` | 9 |
| `wp-mcp-extensibility` | 2 |
| integration adapters (rows with an `integration` key) | 15 |
| **Total** | **285** |

### wp-admin screen → category

| wp-admin area | Screens | Category |
| --- | --- | --- |
| Posts | `edit.php`, `post-new.php`, `post.php` (Quick Edit, bulk trash, scheduling, sticky, password, author, slug), revisions | `wp-mcp-content` |
| Pages | `edit.php?post_type=page`, page attributes, revisions | `wp-mcp-content` |
| Custom post types | `edit.php?post_type=<type>`, registered custom fields only | `wp-mcp-content` (custom post type domain) |
| Media | `upload.php`, `media-new.php`, attachment edit, featured image box | `wp-mcp-media` |
| Categories, tags, custom taxonomies | `edit-tags.php`, `term.php`, term meta | `wp-mcp-taxonomies` |
| Comments | `edit-comments.php`, `comment.php`, moderation, spam, trash, reply | `wp-mcp-comments` |
| Users | `users.php`, `user-new.php`, `user-edit.php`, `profile.php` (Application Passwords); roles have no core screen | `wp-mcp-users` |
| Appearance › Menus | `nav-menus.php` | `wp-mcp-navigation` |
| Appearance › Editor | `site-editor.php` (templates, template parts, patterns, navigation, global styles) | `wp-mcp-site-editor` |
| Appearance › Themes | `themes.php`, `theme-install.php`, updates, auto-updates | `wp-mcp-themes` |
| Plugins | `plugins.php`, `plugin-install.php`, updates, auto-updates | `wp-mcp-plugins` |
| Dashboard › Updates | `update-core.php` (core, translations, available updates) | `wp-mcp-system` |
| Tools | Site Health, Export, Import; cron, caches and maintenance mode have no core screen | `wp-mcp-system` |
| Tools › Export / Erase Personal Data | `export-personal-data.php`, `erase-personal-data.php` | `wp-mcp-privacy` |
| Settings | General, Writing, Reading, Discussion, Media, Permalinks, Privacy (allowlisted fields) | `wp-mcp-settings` |
| Network Admin | Sites, Users, Themes, network plugin activation, Settings, Upgrade Network | `wp-mcp-network` |
| No wp-admin equivalent | agent discovery (`wp-mcp/global-search`, block types, capabilities) and the integrations index | `wp-mcp-discovery`, `wp-mcp-extensibility` |
| Third-party screens | WooCommerce, Yoast SEO, Contact Form 7, Gravity Forms and WPForms, only when the plugin is detected | integration adapters |

### Deliberately not covered

| wp-admin function | Why it stays out | Backed by |
| --- | --- | --- |
| Plugin and theme file editors (`plugin-editor.php`, `theme-editor.php`) | Generic filesystem write and arbitrary PHP: EPIC #1 prohibitions | `test-abilities.php` forbidden-primitive name sweep; `test-security.php` `FORBIDDEN_INPUT_PROPERTIES` sweep |
| `options.php` and any raw option read/write | Generic option access: EPIC #1 prohibition | `test-abilities.php` "no `option` in any ability name" sweep; `test-security.php` settings-allowlist tests |
| Settings holding secrets, site URLs, filesystem paths or `admin_email` (`mailserver_pass`, `siteurl`, `home`, `upload_path`, `admin_email`) | Security invariant in `CLAUDE.md`; `admin_email` needs an e-mail confirmation flow that has no MCP equivalent | `WP_MCP_Settings::never_writable_options()`; `test-security.php` settings tests |
| Free-form custom fields (unregistered, `_`-prefixed or protected post meta) | Only registered, `show_in_rest`, `single`, unprotected keys are exposed | `test-security.php` protected/unregistered post meta tests |
| Customizer (`customize.php`) | No ability exists; the Site Editor covers block themes | Absence from the matrix (the category counts above) |
| Classic widget editing (`widgets.php`) | Only `wp-mcp/list-widget-areas` (read) exists | The `wp-mcp-site-editor` count above |
| Dashboard widgets, Screen Options, admin colour scheme and other per-user UI preferences | Presentation only, no content or site state | — |
