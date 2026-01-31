#!/bin/sh
set -e

# User/Group ID handling
PUID=${PUID:-1000}
PGID=${PGID:-1000}

# Modify user/group if they don't match (for alpine/shadow)
groupmod -o -g "$PGID" www-data
usermod -o -u "$PUID" www-data

# Ensure config directory exists
mkdir -p /config

# Persistence logic for 'data' directory
# The App expects 'data' to be in /var/www/html/data
# We want it in /config/data

# If /config/data doesn't exist, we populate it from the image's default data (if any)
# or just create it.
if [ ! -d "/config/data" ]; then
    echo "Creating data directory in /config..."
    mkdir -p /config/data
    # If the image came with some data pre-populated, move it
    if [ -d "/var/www/html/data" ]; then
        echo "Moving initial data to /config/data..."
        cp -r /var/www/html/data/* /config/data/
        rm -rf /var/www/html/data
    fi
else
    # if /config/data exists, we just need to make sure the app sees it.
    # remove the image's default data dir if it exists to allow symlink
    if [ -d "/var/www/html/data" ] && [ ! -L "/var/www/html/data" ]; then
       echo "Removing default data directory to replace with symlink..."
       rm -rf /var/www/html/data
    fi
fi

# Create symlink
if [ ! -L "/var/www/html/data" ]; then
    echo "Symlinking /config/data to /var/www/html/data..."
    ln -s /config/data /var/www/html/data
fi

# Set permissions
echo "Setting permissions..."
chown -R www-data:www-data /config
chown -R www-data:www-data /var/www/html

# Execute the passed command
exec "$@"
