$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.Net.Http

$baseUrl = 'http://127.0.0.1:8000/api/generos'
$client = New-Object System.Net.Http.HttpClient
$genreId = $null
$created = $false
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
    $listBefore = Invoke-ApiRequest -Method 'GET' -Uri $baseUrl
    Assert-Check (
        $listBefore.StatusCode -eq 200 -and
        $null -ne $listBefore.Data.data -and
        @($listBefore.Data.data).Count -ge 1
    ) 'GET lista los géneros existentes'

    $genreName = 'Prueba VS Code ' + [guid]::NewGuid().ToString('N')
    $genre = @{
        nombre = $genreName
        estado = 'Activo'
        descripcion = 'Género temporal creado para probar el CRUD.'
    }

    $post = Invoke-ApiRequest -Method 'POST' -Uri $baseUrl -Body $genre
    Assert-Check ($post.StatusCode -eq 201 -and $null -ne $post.Data.data.id) 'POST crea un género (HTTP 201)'
    $genreId = [int]$post.Data.data.id
    $created = $true
    Assert-Check ($post.Data.data.nombre -eq $genreName -and $post.Data.data.estado -eq 'Activo') 'POST devuelve sus campos'

    $duplicate = Invoke-ApiRequest -Method 'POST' -Uri $baseUrl -Body $genre
    Assert-Check ($duplicate.StatusCode -eq 409) 'POST rechaza un nombre duplicado (HTTP 409)'

    $get = Invoke-ApiRequest -Method 'GET' -Uri "$baseUrl/$genreId"
    Assert-Check ($get.StatusCode -eq 200 -and $get.Data.data.nombre -eq $genreName) 'GET por ID devuelve el género'

    $replacement = @{
        nombre = "$genreName actualizado"
        estado = 'Inactivo'
        descripcion = 'Descripción reemplazada con PUT.'
    }
    $put = Invoke-ApiRequest -Method 'PUT' -Uri "$baseUrl/$genreId" -Body $replacement
    Assert-Check (
        $put.StatusCode -eq 200 -and
        $put.Data.data.nombre -eq $replacement.nombre -and
        $put.Data.data.estado -eq 'Inactivo'
    ) 'PUT reemplaza los campos del género'

    $patch = Invoke-ApiRequest -Method 'PATCH' -Uri "$baseUrl/$genreId" -Body @{ estado = 'Activo' }
    Assert-Check (
        $patch.StatusCode -eq 200 -and
        $patch.Data.data.estado -eq 'Activo' -and
        $patch.Data.data.descripcion -eq $replacement.descripcion
    ) 'PATCH modifica un campo y conserva los demás'

    $invalidState = Invoke-ApiRequest -Method 'PATCH' -Uri "$baseUrl/$genreId" -Body @{ estado = 'Pendiente' }
    Assert-Check ($invalidState.StatusCode -eq 422) 'PATCH rechaza un estado inválido (HTTP 422)'

    $delete = Invoke-ApiRequest -Method 'DELETE' -Uri "$baseUrl/$genreId"
    Assert-Check ($delete.StatusCode -eq 204) 'DELETE elimina el género temporal (HTTP 204)'
    $created = $false

    $getDeleted = Invoke-ApiRequest -Method 'GET' -Uri "$baseUrl/$genreId"
    Assert-Check ($getDeleted.StatusCode -eq 404) 'GET devuelve 404 después de eliminar'

    Write-Output "Pruebas de géneros completadas correctamente: $checks comprobaciones."
}
finally {
    if ($created -and $null -ne $genreId) {
        try {
            $cleanup = Invoke-ApiRequest -Method 'DELETE' -Uri "$baseUrl/$genreId"
            if ($cleanup.StatusCode -ne 204 -and $cleanup.StatusCode -ne 404) {
                Write-Warning "No se pudo limpiar el género temporal con ID $genreId."
            }
            else {
                Write-Output 'Se limpió el género temporal.'
            }
        }
        catch {
            Write-Warning "No se pudo limpiar el género temporal con ID $genreId. Detalle: $_"
        }
    }

    $client.Dispose()
}
