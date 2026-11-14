<?php

return [
    /*
    | Disque de stockage des fichiers documentaires.
    | 'local' = storage/app (dev/tests) ; 's3' = MinIO/S3 en pile Docker.
    */
    'disk' => env('DOCUMENTS_DISK', 'local'),
];
