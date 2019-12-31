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

## Usage

In most cases, usage will be via `Mezzio\Session\SessionMiddleware`,
and will not require direct access to the service on your part. If you do need
to use it, please refer to the mezzio-session [session persistence
documentation](https://docs.mezzio.dev/mezzio-session/persistence/).
