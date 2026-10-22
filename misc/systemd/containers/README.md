# Drupal Quadlet templates

## What

Quadlet template units, one instance per site. The instance name is `%i` (e.g. `fewobuddy`).

| File | Produces |
| --- | --- |
| `drupal@.pod` | pod `%i` on `proxy` |
| `drupal-db@.volume` | volume `%i__db` |
| `drupal-tmp@.volume` | volume `%i__tmp` |
| `drupal-db@.container` | container `%i__db` |
| `drupal-app@.container` | container `%i__app` |
| `../user/drupal-cron@.service` | runs `drush cron` in `%i__app` |
| `../user/drupal-cron@.timer` | triggers it every 15 minutes |

Both containers run in pod `%i`, which sits on `proxy`. They share its network stack: the app
reaches the DB under host `db` (mapped to 127.0.0.1 by the pod), and MariaDB only listens on
localhost.

## Install

Copy the templates to `~/.config/containers/systemd/`:

```
cp drupal@.pod drupal-db@.volume drupal-tmp@.volume drupal-db@.container drupal-app@.container \
  ~/.config/containers/systemd/
```

The cron units are plain systemd units, not Quadlets:

```
cp ../user/drupal-cron@.service ../user/drupal-cron@.timer ~/.config/systemd/user/
```

## Add a site

```
mkdir -p ~/apps/<site> ~/.config/<site>
cp env.example ~/.config/<site>/.env
chmod 600 ~/.config/<site>/.env
openssl rand -hex 32   # fill DRUPAL_HASH_SALT, MARIADB_PASSWORD, MARIADB_ROOT_PASSWORD
```

Enable the instances with symlinks in `~/.config/containers/systemd/`:

```
ln -s drupal-app@.container drupal-app@<site>.container
ln -s drupal-db@.container drupal-db@<site>.container
systemctl --user daemon-reload
systemctl --user start drupal-app@<site>
```

Enable the cron timer for the site:

```
systemctl --user enable --now drupal-cron@<site>.timer
```

## Per-site overrides

Drop-in: `drupal-app@<site>.container.d/override.conf`.

## Check

```
/usr/libexec/podman/quadlet -dryrun -user
```

The generated `drupal-app@.service` and `drupal-db@.service` must contain `--pod` and depend on
`drupal-pod@%i.service`.

## Note

`MARIADB_*` only apply when the DB volume is initialised for the first time. Changing them later
does not change existing DB passwords.

Moving an existing site into the pod: stop `drupal-app@<site>` and `drupal-db@<site>`,
`daemon-reload`, start `drupal-app@<site>`, then `podman network rm <site>`.
