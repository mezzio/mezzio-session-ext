# mezzio-session-ext

This component provides a persistence adapter for use with
[mezzio-session](https://docs.mezzio.dev/mezzio-session/).

## Installation:

Run the following to install this library:

```bash
$ composer require mezzio/mezzio-session-ext
```

## Configuration

If your application uses the [laminas-component-installer](https://docs.laminas.dev/laminas-component-installer)
Composer plugin, your configuration is complete; the shipped
`Mezzio\Session\Ext\ConfigProvider` registers the
`Mezzio\Session\Ext\PhpSessionPersistence` service, as well as an alias
to it under the name `Mezzio\Session\SessionPersistenceInterface`.

Otherwise, you will need to map `Mezzio\Session\SessionPersistenceInterface`
to `Mezzio\Session\Ext\PhpSessionPersistence` in your dependency
injection container.

### Enabling non locking sessions

The default behaviour of the php session extension is to lock the session file
until *session_write_close* is called - usually at the end of script execution -
in order to safely store the session data. This has the side effect of blocking
every other script that request access to the same session file as for instance
when performing concurrent ajax calls in a Single Page Application. The php session
extension allows us to unlock the session file using the extra option *read_and_close*
in *session_start*.

This option can be enabled using the following configuration:

```php
// file: data/session.global.php
return [
    'session' => [
        'persistence' => [
            'ext' => [
                'non_locking' => true, // true|false, true => read_and_close = true
            ],
        ],
    ],
];
```

As for the php extension, we can use safely use this option only when we are sure
that the session data won't be changed or when only one of the concurrent scripts
may change it. The last script that changes and persists the session data will
overwrite any previous change.

### Enabling deletion of cookie in browser

The default behaviour of the php session extension is to never delete the cookie
in the browser. It is possible to automatically delete the cookie in the browser
when the session becomes empty.

This option can be enabled using the following configuration:

```php
// file: data/session.global.php
return [
    'session' => [
        'persistence' => [
            'ext' => [
                'delete_cookie_on_empty_session' => true, // true|false
            ],
        ],
    ],
];
```

Then whenever you call `$session->clear();`, the cookie will be deleted from in
the browser in addition to deleting session on the server.

This is the recommended configuration to prevent leftover in the browser, unless
you have advanced handling of cookie existence in your application or web server.

## Usage

In most cases, usage will be via `Mezzio\Session\SessionMiddleware`,
and will not require direct access to the service on your part. If you do need
to use it, please refer to the mezzio-session [session persistence
documentation](https://docs.mezzio.dev/mezzio-session/persistence/).
