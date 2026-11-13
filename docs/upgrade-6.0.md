# Upgrade from 5.x to 6.0

```sh
composer require yama6a/subtitle-toolbox-php:^6.0
```

6.0 needs PHP 8.3 or later. [compatibility.md](compatibility.md) says what semantic versioning covers in 6.x. 6.0 has no deprecation period. Each section shows the 5.x call or output and the 6.0 one side by side.

## PHP 8.3

| 5.x | 6.0 |
|:--- |:--- |
| PHP 8.2 or later | PHP 8.3 or later |
| `stbox.phar` runs on PHP 8.2 | `stbox.phar` runs on PHP 8.3 |

- Composer does not install 6.0 on PHP 8.2. Stay on 5.17.6 there.
- The PHP extensions stay the same.
- Greek lower case: `changeCase(CaseMode::Lower)` and the other case changes now follow the Unicode Final_Sigma rule. A sigma after a letter without case, as in U+4E2D U+03A3, becomes U+03C3. 5.x wrote U+03C2. The same holds for a sigma before an apostrophe and a letter.
