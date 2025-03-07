# Configuration
Create a `vizy.php` file under your `/config` directory with the following options available to you. You can also use multi-environment options to change these per environment.

The below shows the defaults already used by Vizy, so you don't need to add these options unless you want to modify the values.

```php
<?php

return [
    '*' => [
        'iconsPath' => '@webroot/icons/',
    ]
];
```

## Configuration options
- `iconsPath` - Provide a file system path for a collection of SVG icons. These are available when creating your Block Types for a Vizy field. This also accepts environment variables or aliases.

## Control Panel
You can also manage configuration settings through the Control Panel by visiting Settings → Vizy.

## Editor Configuration
You can provide configuration files for the editor by providing a `.json` file with its configurations, very similar to how the [Redactor](https://plugins.craftcms.com/redactor) plugin works. From this file, you can define what buttons are shown, what order and more. This can be particularly useful in maintaining consistent configurations for your Vizy fields across projects.

:::tip
Ensure your file is valid JSON, taking care of trailing commas, and double quotes around properties.
:::

For example, create a `vizy` folder in your `/config` directory, and inside that, create a `Minimal.json` file. Place the following content into that file:

```json
{
    "buttons": ["italic", "bold", "undo", "redo"]
}
```

Here, we're setting the buttons available to the editor to only show Italic, Bold, Undo and Redo (in that order). All other buttons and functionality will be disabled.

### Available Options
The below list details the available configuration options to use in your JSON files.

Option | Description
--- | ---
`buttons` | An array of available buttons. Refer to below.
`formatting` | An array of available formatting options. Refer to below.
`toolbarFixed` | A boolean `true/false` to denote if the button toolbar should stick to the viewport when scrolling.

### Buttons

Option | Description
--- | ---
`h1` | Allow the use of `<h1>` heading tags.
`h2` | Allow the use of `<h2>` heading tags.
`h3` | Allow the use of `<h3>` heading tags.
`h4` | Allow the use of `<h4>` heading tags.
`h5` | Allow the use of `<h5>` heading tags.
`h6` | Allow the use of `<h6>` heading tags.
`bold` | Allow text to be bold.
`italic` | Allow text to be italic.
`underline` | Allow text to be underlined.
`strikethrough` | Allow text to have a strikethrough.
`subscript` | Allow text to be subscript.
`superscript` | Allow text to be superscript.
`unordered-list` | Allow the use of `<ul>` elements for an unordered list.
`ordered-list` | Allow the use of `<ol>` elements for an unordered list.
`blockquote` | Allow text to be shown as a blockquote.
`highlight` | Allow text to be highlighted.
`code` | Allow text to be shown as inline code.
`code-block` | Allow text to be shown as a code blocks.
`hr` | Allow the use of a `<hr>` element for a horizontal rule.
`line-break` | Allow the use of a `<br>` element for a horizontal rule.
`align-left` | Allow text to be left-aligned.
`align-center` | Allow text to be center-aligned.
`align-right` | Allow text to be right-aligned.
`align-justify` | Allow text to be justify.
`clear-format` | Allow all formatting to be cleared.
`undo` | Allow undo functionality.
`redo` | Allow redo functionality.
`html` | Allow raw editing of HTML, generated by the editor.
`link` | Allow text to be shown as a link.
`image` | Allow an image to be inserted.
`formatting` | Allow a dropdown for formatting options. See below for configuration.

```json
{
    "buttons": ["italic", "bold", "undo", "redo"]
}
```

You can also provide this as an empty array, to disable buttons completely.

```json
{
    "buttons": []
}
```

### Formatting
You can use the following options in the `formatting` options. Note that you'll also need to include `formatting` as a button in the `buttons` options.

Option | Description
--- | ---
`h1` | Allow the use of `<h1>` heading tags.
`h2` | Allow the use of `<h2>` heading tags.
`h3` | Allow the use of `<h3>` heading tags.
`h4` | Allow the use of `<h4>` heading tags.
`h5` | Allow the use of `<h5>` heading tags.
`h6` | Allow the use of `<h6>` heading tags.
`code-block` | Allow text to be shown as code blocks.
`blockquote` | Allow text to be shown as a blockquote.
`paragraph` | Allow text to be shown as a paragraph.

```json
{
    "buttons": ["formatting", "bold", "italic"],
    "formatting": ["h2", "h3", "h4", "h5", "h6"]
}
```

### Kitchen Sink
The below is a "kitchen sink" example that contains everything.

```json
{
    "buttons": ["html", "formatting", "h1", "h2", "h3", "h4", "h5", "h6", "bold", "italic", "underline", "strikethrough", "subscript", "superscript", "ordered-list", "unordered-list", "code-block", "hr", "highlight", "align-left", "align-right", "align-center", "align-justify", "clear-format", "line-break", "link", "image", "undo", "redo"],
    "formatting": ["paragraph", "code-block", "blockquote", "h1", "h2", "h3", "h4", "h5", "h6"],
    "toolbarFixed": true
}
```
