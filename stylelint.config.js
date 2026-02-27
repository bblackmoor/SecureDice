module.exports = {
    "extends": [
        "stylelint-config-standard"
    ],
    "plugins": [
        "stylelint-order"
    ],
    "rules": {
        "indentation": 4,
        "string-quotes": "double",
        "no-eol-whitespace": true,
        "max-line-length": 120,
        "order/properties-order": [
            [
                {
                    "groupName": "Positioning",
                    "properties": [
                        "position",
                        "top",
                        "right",
                        "bottom",
                        "left",
                        "z-index"
                    ]
                },
                {
                    "groupName": "Display / layout",
                    "properties": [
                        "display",
                        "flex",
                        "flex-direction",
                        "flex-wrap",
                        "flex-flow",
                        "flex-grow",
                        "flex-shrink",
                        "flex-basis",
                        "justify-content",
                        "align-items",
                        "align-content",
                        "place-content",
                        "place-items",
                        "gap",
                        "row-gap",
                        "column-gap",
                        "grid",
                        "grid-template",
                        "grid-template-columns",
                        "grid-template-rows",
                        "grid-template-areas",
                        "grid-auto-flow",
                        "grid-auto-columns",
                        "grid-auto-rows"
                    ]
                },
                {
                    "groupName": "Box model",
                    "properties": [
                        "box-sizing",
                        "width",
                        "min-width",
                        "max-width",
                        "height",
                        "min-height",
                        "max-height",
                        "margin",
                        "margin-top",
                        "margin-right",
                        "margin-bottom",
                        "margin-left",
                        "padding",
                        "padding-top",
                        "padding-right",
                        "padding-bottom",
                        "padding-left"
                    ]
                },
                {
                    "groupName": "Border / radius",
                    "properties": [
                        "border",
                        "border-top",
                        "border-right",
                        "border-bottom",
                        "border-left",
                        "border-color",
                        "border-style",
                        "border-width",
                        "border-radius",
                        "outline",
                        "outline-offset"
                    ]
                },
                {
                    "groupName": "Background",
                    "properties": [
                        "background",
                        "background-color",
                        "background-image",
                        "background-position",
                        "background-repeat",
                        "background-size"
                    ]
                },
                {
                    "groupName": "Typography",
                    "properties": [
                        "font",
                        "font-family",
                        "font-size",
                        "font-weight",
                        "font-style",
                        "line-height",
                        "letter-spacing",
                        "text-align",
                        "text-decoration",
                        "text-transform",
                        "white-space",
                        "color"
                    ]
                },
                {
                    "groupName": "Effects",
                    "properties": [
                        "box-shadow",
                        "transform",
                        "transition"
                    ]
                },
                {
                    "groupName": "Misc",
                    "properties": [
                        "cursor",
                        "opacity",
                        "overflow",
                        "pointer-events"
                    ]
                }
            ],
            {
                "unspecified": "bottom",
                "severity": "warning"
            }
        ]
    }
};
