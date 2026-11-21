$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.Net.Http

$baseUrl = 'http://127.0.0.1:8000/api'
$client = New-Object System.Net.Http.HttpClient
$referenceIds = @{
    genero = $null
    director = $null
    productora = $null
}
$mediaId = $null
$checks = 0

function Invoke-ApiRequest {
    param(
        [Parameter(Mandatory = $true)][string]$Method,
        [Parameter(Mandatory = $true)][string]$Uri,
        [object]$Body = $null
    )

    $request = New-Object System.Net.Http.HttpRequestMessage(
        (New-Object System.Net.Http.HttpMethod($Method)),
        $Uri
    )

    if ($null -ne $Body) {
        $json = ConvertTo-Json -InputObject $Body -Depth 5 -Compress
        $request.Content = New-Object System.Net.Http.StringContent(
            $json,
            [System.Text.Encoding]::UTF8,
            'application/json'
        )
    }

    try {
        $response = $client.SendAsync($request).GetAwaiter().GetResult()
        $content = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
        $data = $null
        if ($content.Length -gt 0) {
            try {
                $data = $content | ConvertFrom-Json
            }
            catch {
                throw "$Method $Uri devolvió JSON inválido: $content"
            }
        }

        return @{
            StatusCode = [int]$response.StatusCode
            Data = $data
        }
    }
    finally {
        $request.Dispose()
    }
}

function Assert-Check {
    param(
        [Parameter(Mandatory = $true)][bool]$Condition,
        [Parameter(Mandatory = $true)][string]$Message
    )

    if (-not $Condition) {
        throw "Falló la prueba: $Message"
    }

    $script:checks++
    Write-Output "OK: $Message"
}

try {
    $suffix = [guid]::NewGuid().ToString('N').Substring(0, 16)

    $genre = Invoke-ApiRequest -Method 'POST' -Uri "$baseUrl/generos" -Body @{
        nombre = "Inactivo-$suffix"
        estado = 'Inactivo'
        descripcion = 'Referencia temporal de prueba.'
    }
    if ($genre.StatusCode -ne 201) {
        throw "No se pudo preparar el género inactivo: HTTP $($genre.StatusCode)"
    }
    $referenceIds.genero = [int]$genre.Data.data.id

    $director = Invoke-ApiRequest -Method 'POST' -Uri "$baseUrl/directores" -Body @{
        nombres = "Inactivo-$suffix"
        estado = 'Inactivo'
    }
    if ($director.StatusCode -ne 201) {
        throw "No se pudo preparar el director inactivo: HTTP $($director.StatusCode)"
    }
    $referenceIds.director = [int]$director.Data.data.id

    $productora = Invoke-ApiRequest -Method 'POST' -Uri "$baseUrl/productoras" -Body @{
        nombre = "Inactivo-$suffix"
        estado = 'Inactivo'
    }
    if ($productora.StatusCode -ne 201) {
        throw "No se pudo preparar la productora inactiva: HTTP $($productora.StatusCode)"
    }
    $referenceIds.productora = [int]$productora.Data.data.id

    $mediaTemplate = @{
        serial = "VSCode-$suffix"
        titulo = 'Película temporal de validación de referencias'
        sinopsis = 'Registro temporal para probar que Media exige referencias activas.'
        url_pelicula = "https://example.com/active-reference-$suffix"
        imagen_portada = 'https://example.com/portada-prueba.jpg'
        ano_estreno = 2024
        genero_id = 1
        director_id = 1
        productora_id = 1
        tipo_id = 1
    }

    foreach ($field in @('genero_id', 'director_id', 'productora_id')) {
        $invalidMedia = $mediaTemplate.Clone()
        $invalidMedia.serial = "VSCode-$suffix-$field"
        $invalidMedia.url_pelicula = "https://example.com/active-reference-$suffix-$field"
        $referenceKey = $field -replace '_id$', ''
        $invalidMedia[$field] = $referenceIds[$referenceKey]

        $response = Invoke-ApiRequest -Method 'POST' -Uri "$baseUrl/medias" -Body $invalidMedia
        Assert-Check (
            $response.StatusCode -eq 422 -and
            $response.Data.error.message -match 'debe estar activo'
        ) "POST rechaza $field inactivo"
    }

    $validMedia = Invoke-ApiRequest -Method 'POST' -Uri "$baseUrl/medias" -Body $mediaTemplate
    if ($validMedia.StatusCode -ne 201) {
        throw "No se pudo crear la película temporal con referencias activas: HTTP $($validMedia.StatusCode)"
    }
    $mediaId = [int]$validMedia.Data.data.id
    Assert-Check ($validMedia.Data.data.id -eq $mediaId) 'POST acepta género, director y productora activos'

    foreach ($field in @('genero_id', 'director_id', 'productora_id')) {
        $invalidUpdate = @{
            $field = $referenceIds[($field -replace '_id$', '')]
        }
        $response = Invoke-ApiRequest -Method 'PATCH' -Uri "$baseUrl/medias/$mediaId" -Body $invalidUpdate
        Assert-Check (
            $response.StatusCode -eq 422 -and
            $response.Data.error.message -match 'debe estar activo'
        ) "PATCH rechaza $field inactivo"
    }

    foreach ($field in @('genero_id', 'director_id', 'productora_id')) {
        $invalidReplacement = $mediaTemplate.Clone()
        $invalidReplacement.serial = "VSCode-$suffix-PUT-$field"
        $invalidReplacement.url_pelicula = "https://example.com/active-reference-$suffix-PUT-$field"
        $referenceKey = $field -replace '_id$', ''
        $invalidReplacement[$field] = $referenceIds[$referenceKey]
        $response = Invoke-ApiRequest -Method 'PUT' -Uri "$baseUrl/medias/$mediaId" -Body $invalidReplacement
        Assert-Check (
            $response.StatusCode -eq 422 -and
            $response.Data.error.message -match 'debe estar activo'
        ) "PUT rechaza $field inactivo"
    }

    $typeOnly = Invoke-ApiRequest -Method 'PATCH' -Uri "$baseUrl/medias/$mediaId" -Body @{ tipo_id = 2 }
    Assert-Check (
        $typeOnly.StatusCode -eq 200 -and
        [int]$typeOnly.Data.data.tipo_id -eq 2
    ) 'PATCH acepta un tipo existente sin estado'

    $deleteMedia = Invoke-ApiRequest -Method 'DELETE' -Uri "$baseUrl/medias/$mediaId"
    Assert-Check ($deleteMedia.StatusCode -eq 204) 'Se elimina la película temporal'
    $mediaId = $null

    Write-Output "Pruebas de referencias activas completadas: $checks comprobaciones."
}
finally {
    if ($null -ne $mediaId) {
        try {
            Invoke-ApiRequest -Method 'DELETE' -Uri "$baseUrl/medias/$mediaId" | Out-Null
        }
        catch {
            Write-Warning "No se pudo eliminar la película temporal con ID $mediaId. Detalle: $_"
        }
    }

    foreach ($reference in @(
        @{ Resource = 'productoras'; Key = 'productora' },
        @{ Resource = 'directores'; Key = 'director' },
        @{ Resource = 'generos'; Key = 'genero' }
    )) {
        $id = $referenceIds[$reference.Key]
        if ($null -ne $id) {
            try {
                Invoke-ApiRequest -Method 'DELETE' -Uri "$baseUrl/$($reference.Resource)/$id" | Out-Null
            }
            catch {
                Write-Warning "No se pudo eliminar la referencia temporal $($reference.Resource)/$id. Detalle: $_"
            }
        }
    }

    $client.Dispose()
}
