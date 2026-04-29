# mod_scorecard — Moodle templating conventions

A small living set of conventions around when to use Mustache templates vs. when to keep markup generation in PHP. Compiled from refactor experience; expand as further patterns emerge.

## Templatize author markup; keep Moodle-output-API calls in PHP

When refactoring inline `html_writer` chains into Mustache templates, the line to draw is:

- **Templatize**: markup the plugin author writes by hand — wrappers, layout, headings, copy, conditional blocks. Move these to `templates/<name>.mustache`, build a context array in PHP, render via `$this->render_from_template()`.
- **Keep in PHP**: calls into Moodle's output APIs that produce context-aware HTML — `pix_icon()`, `single_button()`, `action_link()`, `notification()`, anything that resolves theme/icon/accessibility state. Render these to a string in PHP, then pass the rendered HTML into the template as a context value (or compose the surrounding markup in PHP if a template wrapper would be more awkward than helpful).

### Why

Moodle's output APIs encode runtime state Mustache can't reasonably reach: icon path resolution against the active theme, accessibility-attribute composition, link-action JS hookup, deprecation handling. Replicating their HTML by hand in a template recreates this logic at a stale snapshot — it'll drift when Moodle's output API evolves. Calling them from PHP and passing the resulting string into the template preserves the API contract.

### Empirical case

The renderer refactor at commit `3001a83` moved most of `classes/output/renderer.php`'s markup to Mustache templates but kept the item-row and band-row action-link clusters in PHP because they wrap `pix_icon()` calls. The pre-rendered cluster passes into the row template as a single `actions` HTML string. This carve-out emerged during the refactor work itself rather than from a pre-planned design — it surfaced as the obvious right answer once the refactor encountered the API-call sites. The principle articulated above is the post-hoc generalization of that discovery; future refactor work has direct precedent rather than rediscovering the same line.

### Heuristic for borderline cases

If you're tempted to write `<i class="...">` markup in a template to mimic something Moodle has an output API for, stop and pre-render in PHP instead. If the markup is purely author-written (your own div/p/h2 structure with author-controlled classes), templatize it.
