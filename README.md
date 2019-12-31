# cron-shop
sistema de facturas e envio de email crontab

agendamento crontab via php

corre de 1 em 1 minuto
```
*/1 * * * * php /var/www/html/**/**/cron-shop.php
```

agendamento crontab via curl

corre de 1 em 1 minuto
```
*/1 * * * *   curl https://link/**/**/cron-shop.php
```
