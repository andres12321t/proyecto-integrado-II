$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.Net.Http

$baseUrl = 'http://127.0.0.1:8000/api/medias'
$client = New-Object System.Net.Http.HttpClient
$mediaId = $null
$created = $false
$checks = 0

function Invoke-ApiRequest {
    param(
        [Parameter(Mandatory = $true)][string]$Method,
        [Parameter(Mandatory = $true)][string]$Uri,
        [object]$Body = $null
    )

    $httpMethod = New-Object System.Net.Http.HttpMethod($Method)
    $request = New-Object System.Net.Http.HttpRequestMessage($httpMethod, $Uri)

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

        if (-not $response.IsSuccessStatusCode) {
            throw "$Method $Uri respondió HTTP $([int]$response.StatusCode): $content"
        }

        $data = $null
        if ($content.Length -gt 0) {
            $data = $content | ConvertFrom-Json
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
    $listBefore = Invoke-ApiRequest -Method 'GET' -Uri $baseUrl
    Assert-Check ($listBefore.StatusCode -eq 200 -and $null -ne $listBefore.Data.data) 'GET lista registros'

    $suffix = [guid]::NewGuid().ToString('N')
    $media = @{
        serial = "VSCode-$suffix"
        titulo = 'Película temporal de prueba'
        sinopsis = 'Registro temporal para verificar el CRUD desde VS Code.'
        url_pelicula = "https://example.com/$suffix"
        imagen_portada = 'https://example.com/portada-prueba.jpg'
        ano_estreno = 2024
        genero_id = 1
        director_id = 1
        productora_id = 1
        tipo_id = 1
    }

    $post = Invoke-ApiRequest -Method 'POST' -Uri $baseUrl -Body $media
    Assert-Check ($post.StatusCode -eq 201 -and $null -ne $post.Data.data.id) 'POST crea el registro (HTTP 201)'
    $mediaId = [int]$post.Data.data.id
    $created = $true
    Assert-Check ($post.Data.data.director -eq 'Christopher Nolan') 'POST devuelve correctamente los datos relacionados'

    $list = Invoke-ApiRequest -Method 'GET' -Uri $baseUrl
    $foundInList = @($list.Data.data | Where-Object { [int]$_.id -eq $mediaId }).Count -eq 1
    Assert-Check $foundInList 'GET lista incluye el registro nuevo'

    $get = Invoke-ApiRequest -Method 'GET' -Uri "$baseUrl/$mediaId"
    Assert-Check ($get.Data.data.serial -eq $media.serial) 'GET por ID devuelve el registro correcto'

    $replacement = $media.Clone()
    $replacement.titulo = 'Película temporal actualizada con PUT'
    $replacement.ano_estreno = 2025
    $replacement.genero_id = 2
    $put = Invoke-ApiRequest -Method 'PUT' -Uri "$baseUrl/$mediaId" -Body $replacement
    Assert-Check ($put.Data.data.titulo -eq $replacement.titulo -and $put.Data.data.genero -eq 'Aventura') 'PUT reemplaza campos y relación'

    $patch = Invoke-ApiRequest -Method 'PATCH' -Uri "$baseUrl/$mediaId" -Body @{ titulo = 'Película temporal actualizada con PATCH' }
    Assert-Check (
        $patch.Data.data.titulo -eq 'Película temporal actualizada con PATCH' -and
        [int]$patch.Data.data.ano_estreno -eq 2025
    ) 'PATCH cambia el título y conserva los demás campos'

    $delete = Invoke-ApiRequest -Method 'DELETE' -Uri "$baseUrl/$mediaId"
    Assert-Check ($delete.StatusCode -eq 204) 'DELETE elimina el registro temporal (HTTP 204)'
    $created = $false

    $getDeleted = $null
    try {
        $getDeleted = Invoke-ApiRequest -Method 'GET' -Uri "$baseUrl/$mediaId"
    }
    catch {
        if ($_ -notmatch 'HTTP 404') {
            throw
        }
    }
    Assert-Check ($null -eq $getDeleted) 'GET devuelve 404 después de eliminar'

    Write-Output "Pruebas completadas correctamente: $checks comprobaciones."
}
finally {
    if ($created -and $null -ne $mediaId) {
        try {
            Invoke-ApiRequest -Method 'DELETE' -Uri "$baseUrl/$mediaId" | Out-Null
            Write-Output 'Se limpió el registro temporal.'
        }
        catch {
            Write-Warning "No se pudo limpiar el registro temporal con ID $mediaId. Detalle: $_"
        }
    }

    $client.Dispose()
}
