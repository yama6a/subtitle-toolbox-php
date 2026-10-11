# Upgrade from 3.x to 4.0

```sh
composer require yama6a/subtitle-toolbox-php:^4.0
```

4.0 needs the same PHP version and extensions as 3.x. [compatibility.md](compatibility.md) says what semantic versioning covers in 4.x. 4.0 has no deprecation period. Each section shows the 3.x call and the 4.0 call side by side.

## Alignment

TODO #675 #771

## Read and write options

TODO #709

## Exceptions

TODO #710 #715

## Sync

`Subtitle::syncByTwoPoints()` is removed. `syncByPoints()` with 2 `SyncPoint` objects gives the same result.

```php
// 3.x
$subtitle->syncByTwoPoints(10, 12, 6260, 6005);
// 4.0
$subtitle->syncByPoints([new SyncPoint(oldSeconds: 10, newSeconds: 12), new SyncPoint(oldSeconds: 6260, newSeconds: 6005)]);
```

`syncByTwoPoints()` took its 2 points in either order. `syncByPoints()` needs the old and new times to increase from point to point.

## CLI diff

TODO #711
