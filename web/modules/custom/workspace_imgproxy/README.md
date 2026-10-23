# imgproxy

## Functionality

- serve every image style through imgproxy instead of a Drupal image toolkit
- keep the image styles as the description of a derivative: their effects are
  translated into imgproxy processing options, nothing is written to disk

## How it works

`hook_entity_type_alter()` swaps the `image_style` entity class for
`ImgproxyImageStyle`, whose `buildUrl()` returns an imgproxy URL. Everything
that reads an image style — the responsive image styles, the field formatters,
the media library — keeps working unchanged.

`StyleOptions` translates the core effects:

| Effect                 | imgproxy                               |
| ---------------------- | -------------------------------------- |
| `image_scale`          | `rs:fit:…` with the upscale flag       |
| `image_resize`         | `rs:force:…`                           |
| `image_scale_and_crop` | `rs:fill:…` plus the anchor as gravity |
| `image_crop`           | `c:…` plus the anchor as gravity       |
| `image_desaturate`     | `sa:0`                                 |
| `image_rotate`         | `rot:…`, only in steps of 90 degrees   |
| `image_convert`        | `f:…`                                  |
| `image_convert_avif`   | nothing, see below                     |

"Convert to AVIF, with a fallback if AVIF is not supported" is what imgproxy
does on its own from the `Accept` header of the request
(`IMGPROXY_AUTO_AVIF`/`IMGPROXY_AUTO_WEBP`), so pinning a format for that effect
would take the fallback away. Any other effect is logged to the `workspace_imgproxy`
channel and skipped — there is no way to reproduce it.

A URI outside the public files directory, and an environment without an
imgproxy instance, fall back to a derivative Drupal renders itself.

## Where imgproxy reads the originals

By default the source of a derivative is `local:///<path>`, read below
`IMGPROXY_LOCAL_FILESYSTEM_ROOT` — the shared files directory. The backend is
behind basic auth and a self-signed certificate in every environment but
production, so a deployed instance has no usable HTTP source anyway.

A `source_base_url` replaces that with an HTTP URL below it, and locally it is
set to the ddev web container: `http://web/sites/default/files`, which is past
the router and so past both the basic auth and the certificate.

That is what makes `stage_file_proxy` work. It is a `KernelEvents::REQUEST`
subscriber and only fetches a file from the staging origin when a request for
the files directory reaches Drupal's kernel. Off the filesystem, a missing
original is a 404 imgproxy answers on its own and Drupal never learns about;
over HTTP it is a request the subscriber sees, downloads the original for, and
redirects back to — hence `IMGPROXY_MAX_REDIRECTS` on the container, without
which that first request still fails.

The trade-off is the extra hop per cold derivative, and that a source URL is a
source anyone who can reach imgproxy could point elsewhere — which is why the
host is the internal one and `IMGPROXY_ALLOWED_SOURCES` pins it.

## Settings

| Setting                                    | Environment                | Meaning                                                                                                              |
| ------------------------------------------ | -------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| `$settings['imgproxy']['base_url']`        | `IMGPROXY_BASE_URL`        | Browser-facing base URL, empty disables the module                                                                   |
| `$settings['imgproxy']['source_base_url']` | `IMGPROXY_SOURCE_BASE_URL` | Base URL of the public files directory imgproxy fetches originals over HTTP from, empty reads the filesystem instead |
| `$settings['imgproxy']['key']`             | `IMGPROXY_KEY`             | Signing key, hex encoded                                                                                             |
| `$settings['imgproxy']['salt']`            | `IMGPROXY_SALT`            | Signing salt, hex encoded                                                                                            |

The settings win over the environment, so any of the three can be overridden
per developer in `web/sites/default/settings.local.php`, which
`settings.php` includes last:

```php
$settings['imgproxy'] = [
  'base_url' => 'https://images.drupal-ai-starter.localhost',
  'key' => '…',
  'salt' => '…',
];
```

Locally the environment side is `.ddev/.env`, which reaches both the web
container and the imgproxy container — the two have to hold the same key and
salt or every signature is rejected with a 403. That pair is a throwaway
committed for development only. **A deployed environment sets its own, from its
deployment secrets and never from this repository** — an imgproxy without URL
signing resizes anything below its source root for anyone who asks.

Without a key and a salt the module emits `insecure` as the signature, which is
what imgproxy expects when signing is off.

Turning signing on, or rotating the key, does not invalidate what the cache
already holds: entries fetched under the old rules keep being served until they
are evicted. Clear it explicitly when either changes:

```
docker exec ddev-drupal-ai-starter-imgproxy-cache sh -c 'rm -rf /var/cache/imgproxy/*'
```

The local instance is `.ddev/docker-compose.imgproxy.yaml`, reachable at
https://images.drupal-ai-starter.localhost. It reads `web/sites/default/files` directly
rather than fetching over HTTP, because the backend is behind basic auth and a
self-signed certificate everywhere but production.

## Cache

imgproxy has no cache of its own — it re-decodes and re-encodes on every
request — so an nginx caching reverse proxy sits in front of it and is the only
thing routed publicly: `images.drupal-ai-starter.localhost` is that cache, imgproxy
itself is reachable inside the project network only.

| Variable                   | Default | Meaning                                                               |
| -------------------------- | ------- | --------------------------------------------------------------------- |
| `IMGPROXY_CACHE_SIZE`      | `1g`    | Disk budget, least recently used entries are evicted to stay under it |
| `IMGPROXY_CACHE_INACTIVE`  | `30d`   | How long an untouched entry survives                                  |
| `IMGPROXY_CACHE_KEYS_ZONE` | `10m`   | Shared memory for the keys, ~8000 per megabyte                        |

nginx rather than Caddy, which the rest of this project serves with: a byte
budget for the cache is what Caddy has no module for. Its only one that offers
one (`cache-handler` with the `simplefs` storage) parses the value with
`strconv.Atoi`, so `1GB` silently becomes 0 — unlimited — and the request that
first has to evict deadlocks into a 504. `max_size` is stock nginx.

`X-Cache-Status` on every response says `HIT`, `MISS` or `EXPIRED`. imgproxy
sends `Vary: Accept` for its AVIF/WebP negotiation and nginx honours it, so a
client that cannot read AVIF is never handed one from the cache.

## Image styles

Two families, both mapped to a `sizes` attribute by a responsive image style.

**Uncropped**, `w480` … `w2400`: scale to a width and keep the ratio of the
original. Used by `zoom`, the media picker's full-size view, which is
`object-contain` — the point of a zoom is the picture as it was uploaded.

**Cropped**, `w120_4x3` … `w3840_1x1`: `image_scale_and_crop` to a fixed aspect
ratio, centred, in the responsive image styles `ratio_4x3`, `ratio_16x9` and
`ratio_1x1`. The ladder is

    120 160 192 240 320 400 480 640 800 960 1200 1440 1920 2400 3200 3840

which is a constant ~1.26× per step rather than a constant pixel increment:
file size grows with the square of the width, so equal _relative_ steps are
equal steps in bytes — dense where images are small, sparse at the top, where
the same relative step is hundreds of pixels. Every width past the first is a
multiple of 16, so the 4:3 and 16:9 heights are whole numbers; only 120 × 16:9
rounds (67.5 → 68, 0.7% off the ratio).

`card` maps the 16:9 rungs from 240 to 1200, which is what the card layout can
ask for; `avatar` is one style for both boxes the same user picture is
rendered in: 20/40/60 is the 20px avatar of the user menus at 1x, 2x and 3x,
and 44/88/132 the 44px one of the settings card. Its fallback is the 20×20
derivative, because the menus render the plain `url` and the settings card
reads the srcset. `thumb` is a single 48×48 square for the listing rows of the
dashboard, where a srcset would buy nothing.

The ratio styles' `sizes` is `100vw`, because a ratio is not a layout. A layout with known
slot widths deserves its own responsive image style the way `card` has one.

## Production

Two podman units, `config/container/imgproxy/` and
`config/container/imgproxy-cache/`. `config/container`
is excluded from rsync, so they are copied to the host once rather than
deployed — the header of each unit says how.

Both join the project's pod, whose containers share one network namespace: Drupal
holds `:80` there, so imgproxy binds `127.0.0.1:8080` and the
cache listens on `:8081`, which is what the front Caddy proxies:

```
images.example.com {
        import binds
        reverse_proxy <pod>:8081
}
```

Three files the host owns, none of them in this repository:

| Path                                          | What                                                                                                                |
| --------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `~/.config/<project>/imgproxy.env`            | `IMGPROXY_KEY` and `IMGPROXY_SALT`, `chmod 600`                                                                     |
| `~/.config/<project>/default.conf.template`   | copy of `config/container/imgproxy-cache/default.conf.template`                                                     |
| `shared/web/sites/default/settings.local.php` | `$settings['imgproxy']['key'`/`'salt'`/`'base_url']` — the same key and salt, or Drupal signs URLs imgproxy rejects |

Neither unit needs restarting per release: imgproxy reads the shared files
directory live, and derivative URLs are immutable. The cache does need a flush
when the key or the salt changes.
