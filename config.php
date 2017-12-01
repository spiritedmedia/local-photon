<?php

// Don't upscale images higher than 3072px
define( 'UPSCALE_MAX_PIXELS', 3072 );

add_filter( 'external_fallback_domain', function() {
  return 'https://a.spirited.media';
} );
