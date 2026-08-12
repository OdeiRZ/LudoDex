<?php

return [
    'token_missing' => 'Falta configurar BGG_APPLICATION_TOKEN (BoardGameGeek exige un token de aplicación registrado; ver https://boardgamegeek.com/using_the_xml_api).',
    'unreachable' => 'No se pudo contactar con BoardGameGeek.',
    'unexpected_response' => 'Respuesta inesperada de BoardGameGeek.',
    'user_not_found' => 'Usuario de BoardGameGeek no encontrado.',
    'game_not_found' => 'No se ha encontrado ningún juego con ese id en BoardGameGeek.',
    'csv_invalid_format' => 'Este archivo no parece ser una exportación de colección de BoardGameGeek (faltan columnas esperadas).',
    'csv_expansion_not_linked' => 'La expansión ":name" se ha importado, pero no se ha podido enlazar con su juego base (no está en este mismo archivo).',
];
