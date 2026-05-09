# FORMATTING.md

# Formatting Contract

This document defines mandatory formatting and structural rules for
HTML, CSS, and JavaScript across this and future projects.

These rules are deterministic and must be followed exactly.

The goals are:

-   Deterministic formatting
-   Machine-enforceable structure
-   Zero ambiguity
-   Long-term consistency

------------------------------------------------------------------------

# 1. Global Rules

## 1.1 Indentation

-   Use 4 spaces.
-   Tabs are forbidden.
-   Mixed indentation is forbidden.

## 1.2 Line Endings

-   Use LF (`\n`).
-   No CRLF.
-   Exactly one newline at end of file.
-   No trailing whitespace.

## 1.3 Line Length

-   Preferred maximum: 120 characters.
-   Wrap long expressions.
-   Do not compress code to fit width.

## 1.4 Quotes

-   HTML attributes: double quotes (`"`).
-   JavaScript strings: double quotes (`"`).
-   CSS strings: double quotes (`"`).
-   Single quotes are forbidden unless syntactically required.

## 1.5 Semicolons

-   Required in JavaScript.
-   Required in CSS.
-   Final CSS declaration must include a semicolon.
-   Never omit semicolons.

------------------------------------------------------------------------

# 2. HTML Rules

## 2.1 Case

-   All tags lowercase.
-   All attribute names lowercase.
-   Always close non-void tags.

## 2.2 Void Elements

In documents served as `text/html`, void elements must not use a
self-closing slash (`/>`).

Correct:

``` html
<br>
<input type="text">
```

Incorrect:

``` html
<br />
<input type="text" />
```

In JSX or XHTML, use the syntax required by that environment.

## 2.3 Attribute Formatting

If entire tag length ≤ 120 characters: - Keep on one line.

If \> 120 characters: - Use multiline format.

Required multiline format:

``` html
<input
    id="mod"
    name="mod"
    type="text"
    inputmode="numeric"
    value="0"
    required
>
```

Rules:

-   Opening tag name remains on first line.
-   One attribute per line.
-   Attributes indented 4 spaces.
-   Closing `>` on its own line.
-   No trailing spaces.

## 2.4 PHP in HTML

-   Use short echo syntax: `<?= ?>`.
-   One space inside parentheses.
-   Avoid complex logic inside attributes.
-   Short ternary expressions allowed if readable.

## 2.5 Tables

-   `<tr>`, `<td>`, `<th>` each on their own lines.
-   No inline styling.
-   Indentation must reflect DOM hierarchy.

## 2.6 Blank Lines

-   Use blank lines to separate major sections (e.g., header, main,
    footer).
-   Do not use more than one consecutive blank line.
-   Do not use blank lines that do not improve structural clarity.

## 2.7 No Inline Code

Prohibited:

``` html
<button onclick="doThing()">
```

All JavaScript must be external. No inline `<style>` blocks.

------------------------------------------------------------------------

# 3. CSS Rules

## 3.1 Block Formatting

Single-line CSS blocks are forbidden.

Incorrect:

``` css
.selector { color: red; }
```

Correct:

``` css
.selector {
    color: red;
}
```

Rules:

-   Opening brace on same line as selector.
-   One property per line.
-   Properties indented 4 spaces.
-   Closing brace on its own line.
-   Exactly one blank line between rule blocks.

## 3.2 Declaration Formatting

-   One space after colon.
-   One property per line.
-   Semicolon required.
-   No alignment columns.
-   No grouped declarations.

Correct:

``` css
font-size: 14px;
color: #000000;
```

## 3.3 CSS Variables

Pattern:

    --[prefix]-[category]-[descriptor]

Rules:

-   Lowercase only.
-   Hyphen separated.
-   Project prefix required.

Example:

``` css
--sd2-color-dark-blue: #003399;
```

## 3.4 Property Order

Within each block:

1.  Positioning (position, top, left, z-index)
2.  Display / layout (display, flex, grid)
3.  Box model (width, height, margin, padding)
4.  Border / radius
5.  Background
6.  Typography
7.  Effects (box-shadow, transition)
8.  Miscellaneous

Order must not be randomized.

## 3.5 CSS File Section Order

1.  `:root`
2.  Global elements
3.  Layout containers
4.  Components
5.  Utilities
6.  Media queries
7.  Print rules

------------------------------------------------------------------------

# 4. JavaScript Rules

## 4.1 File Structure

All non-module JS files must be wrapped in an IIFE.

Allowed forms:

(function () {
    "use strict";

})();

(() => {
    "use strict";

})();

Use one style consistently within the project.

No global variables.

If using ES modules (`type="module"`), do not use an IIFE; module scope
replaces it.

## 4.2 Declarations

-   `var` is forbidden.
-   Prefer `const`.
-   Use `let` only if reassignment is required.

## 4.3 Equality

Only strict equality allowed.

Correct:

``` js
if (value === "sum") {
}
```

Incorrect:

``` js
if (value == "sum") {
}
```

## 4.4 Braces

Control structures must use braces, except for simple guard-clause early returns.

Allowed:

```js
if (!el) return;
if (value === null) return;
```

Required:

```js
if (x) {
    doThing();
}

if (x) {
    doThing();
} else {
    doOtherThing();
}
```

## 4.5 Event Handling

Must use:

``` js
element.addEventListener("click", function () {
});
```

Never:

``` js
element.onclick = ...
```

## 4.6 Formatting

-   Opening brace on same line.
-   One statement per line.
-   One blank line between logical sections.
-   One blank line between top-level functions.
-   No nested function declarations unless necessary.

## 4.7 Reset Behavior Rule

If `form.reset()` is used:

-   It must restore initial DOM-defined values.
-   It must re-trigger UI synchronization logic.

------------------------------------------------------------------------

# 5. Prohibited Patterns

-   Tabs.
-   Trailing whitespace.
-   Single-line CSS blocks.
-   Inline JavaScript.
-   Inline CSS.
-   Mixed quote styles.
-   Commented-out production code.
-   Magic numbers without named constants.
-   Reverting previously removed features unless explicitly requested.

------------------------------------------------------------------------

# 6. Tooling Expectation

Projects should include:

-   `.editorconfig`
-   `.prettierrc`
-   `stylelint.config.js`

All generated code must conform without manual reformatting.

------------------------------------------------------------------------

This file governs formatting across current and future projects unless
explicitly overridden.
