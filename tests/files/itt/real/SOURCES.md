# Sources

| File | Source | License |
|:--- |:--- |:--- |
| `avscript_testing.itt` | https://github.com/avscript-tv/avscript/blob/e65bc4e1623e2ccbea2bb9db1a488073f60a00f0/xml%20exchange/svegatoga_iTT_English.itt | avscript, MIT |
| `fcp_23976_styles.itt` | Written for this repository in the shape of the Final Cut Pro iTT export, with a top and a bottom region at 23.976 fps | MIT |
| `bom_2997_crlf.itt` | Written for this repository with a UTF-8 BOM, CRLF line endings, a `ttm:title` and times past one hour at 29.97 fps | MIT |
| `expected/*.json` | Generated for this repository. Expected parse result of the file with the same name, written by `tests/files/generate-read-goldens.php` | MIT |
| `expected/*.out.*` | Generated for this repository. Expected `IttFormatter` output for the file with the same name, written by `tests/files/generate-write-goldens.php`. Checked by hand: the formatter drops the unused namespaces and the region on `<body>` | MIT |
| `expected/*.to.*` | Generated for this repository. Expected output of the file with the same name in another format, written by `tests/files/generate-write-goldens.php` | MIT |
