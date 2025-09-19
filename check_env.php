<?php
require __DIR__ . '/auth/config.php';

printf(
  "SITE_KEY? %s\nSECRET? %s\nPEPPER_LEN %d\n",
  CAPTCHA_SITE_KEY !== '' ? 'true' : 'false',
  CAPTCHA_SECRET   !== '' ? 'true' : 'false',
  strlen(APP_TOKEN_PEPPER)
);
