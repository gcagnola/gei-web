<?php

return [
    // Solo activar luego de conciliar exactamente GIMB38 de COBOL con al menos
    // el caso de control y verificar tasa, IVA, redondeo y cierre por sede.
    'gimb38_validado' => env('GEI_GIMB38_VALIDADO', false),
];
