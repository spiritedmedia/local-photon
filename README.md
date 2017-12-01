# Local Photon - For Dynamic Image Resizing

## What does it do?
This repo piggybacks on WordPress' open-source [Photon project](https://developer.wordpress.com/docs/photon/) letting you dynamically resize and manipulate images via a URL.

## How does it work?
We map requests to jpg, jpeg, png, and gif URLs to run through a PHP script that uses Photon to resize and manipulate the images. If the images don't exist locally then they can be mapped to an external URL.

Example:

`http://example.dev/img/picture.jpg?w=250` will resize an image to 250 pixels wide. If `http://example.dev/img/picture.jpg` doesn't exist the script can automatically fallback to making a request to an external domain like `http://example.com/img/picture.jpg` to use for resizing and manipulation.

## How do I install it?
Copy `image-proxy.php` to the root of your site. Add the contents of `local-photon.nginx.conf` to your nginx servers configuration which will map requests to image through the `image-proxy.php` script. The proxy script expects a copy of the Photon project one level above the root directory of your site. You can checkout the latest version of Photon like so:

```
sudo svn co http://code.svn.wordpress.org/photon/
```

Photon also requires the [GraphicsMagick library](http://www.graphicsmagick.org/) See `install-graphicsmagick.sh` for details

## How can I configure this script?
In `image-proxy.php` you can change the location of where the Photon project is located. You can also add a `config.php` file with filters to change various aspects of Photon.

To specify an external domain to proxy requests that are missing locally add the following to `config.php`:

```
add_filter( 'external_fallback_domain', function() {
  return 'https://external-domain.com';
} );
```
