# Child themes

The website's pages (home, features, pricing, contact, the legal pages) come from the default theme in
`resources/views/theme/`. To change one, make a child theme here and copy only the templates you change into it,
at the same path:

```
themes/
  my-brand/
    views/
      sections/
        features.blade.php   ← your copy of resources/views/theme/sections/features.blade.php
```

Then name it in `.env`: `KIT_THEME=my-brand` (a folder name: lowercase letters, digits, `-` and `_`). Your copy
wins; every template you did not copy still comes from the default theme, and an update of the platform never
touches this folder. Admin → Website shows the child theme in use.

- `layout.blade.php` is the frame of every page. To add a style sheet or a script without copying the whole layout,
  make `views/partials/head.blade.php`: it is added at the end of every page's `<head>`.
- The words (texts, sections, plans, legal pages) are edited in Admin → Website, not here: a template shows them.
  Show the operator's text with `{{ }}` (escaped), never `{!! !!}`.
- Files a page loads (images, style sheets) go in `public/`, for example `public/my-brand/`.
