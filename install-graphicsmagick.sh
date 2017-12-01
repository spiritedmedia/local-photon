#! /bin/bash

# This installs GraphicsMagick and dependencies to make Photon work.
# Tested on Ubuntu 14.04 and 16.04 with EasyEngine.io

function command_exists () {
    type "$1" &> /dev/null ;
}

# Make sure we have PECL installed
if ! command_exists pecl ; then
    sudo apt-get install --yes php-pear
fi

# Install phpize and GraphicsMagick
# Note: We're using php7
# `libgraphicsmagick1-dev` adds `GraphicsMagick-config` to `/usr/bin` which is needed for the pecl install step to work
sudo apt-get install --yes php7.0-dev graphicsmagick libgraphicsmagick1-dev

# Install the PHP extension (You can check for the latest version at http://pecl.php.net/package/gmagick)
sudo pecl install gmagick-2.0.4RC1

# Create a gmagick specific configuration file to load the PHP extension
sudo ln -s /etc/php/7.0/mods-available/gmagick.ini /etc/php/7.0/fpm/conf.d/20-gmagick.ini

sudo cat > /etc/php/7.0/mods-available/gmagick.ini << EOF
; configuration for php graphicsmagick module
; priority=20
extension=gmagick.so
EOF

# Install various image libraries required by Photon
sudo apt-get install --yes optipng pngquant jpegoptim webp

# Link the tools to /usr/local/bin/* which is what the Photon script expects
sudo ln -sf /usr/bin/jpegoptim /usr/local/bin/jpegoptim
sudo ln -sf /usr/bin/optipng /usr/local/bin/optipng
sudo ln -sf /usr/bin/pngquant /usr/local/bin/pngquant
sudo ln -sf /usr/bin/cwebp /usr/local/bin/cwebp
