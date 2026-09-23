<?php
echo 'theme=' . get_option( 'stylesheet' ) . ' fn=' . ( function_exists( 'prime_file_download_url' ) ? 'yes' : 'no' ) . " wc=" . ( class_exists( 'WooCommerce' ) ? 'yes' : 'no' ) . "\n";
