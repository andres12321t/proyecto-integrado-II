$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.Net.Http

$baseUrl = 'http://127.0.0.1:8000/api/directores'
$client = New-Object System.Net.Http.HttpClient
$directorId = $null
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
    ) 'GET lista los directores existentes'

    $directorName = 'Prueba VS Code ' + [guid]::NewGuid().ToString('N')
    $director = @{
        nombres = $directorName
        estado = 'Activo'
    }

    $post = Invoke-ApiRequest -Method 'POST' -Uri $baseUrl -Body $director
    Assert-Check ($post.StatusCode -eq 201 -and $null -ne $post.Data.data.id) 'POST crea un director (HTTP 201)'
    $directorId = [int]$post.Data.data.id
    $created = $true
    Assert-Check (
        $post.Data.data.nombres -eq $directorName -and $post.Data.data.estado -eq 'Activo'
    ) 'POST devuelve sus campos'

    $get = Invoke-ApiRequest -Method 'GET' -Uri "$baseUrl/$directorId"
    Assert-Check (
        $get.StatusCode -eq 200 -and $get.Data.data.nombres -eq $directorName
    ) 'GET por ID devuelve el director'

    $replacement = @{
        nombres = "$directorName actualizado"
        estado = 'Inactivo'
    }
    $put = Invoke-ApiRequest -Method 'PUT' -Uri "$baseUrl/$directorId" -Body $replacement
    Assert-Check (
        $put.StatusCode -eq 200 -and
        $put.Data.data.nombres -eq $replacement.nombres -and
        $put.Data.data.estado -eq 'Inactivo'
    ) 'PUT reemplaza los campos del director'

    $patch = Invoke-ApiRequest -Method 'PATCH' -Uri "$baseUrl/$directorId" -Body @{ estado = 'Activo' }
    Assert-Check (
        $patch.StatusCode -eq 200 -and
        $patch.Data.data.estado -eq 'Activo' -and
        $patch.Data.data.nombres -eq $replacement.nombres
    ) 'PATCH modifica el estado y conserva el nombre'

    $invalidState = Invoke-ApiRequest -Method 'PATCH' -Uri "$baseUrl/$directorId" -Body @{ estado = 'Pendiente' }
    Assert-Check ($invalidState.StatusCode -eq 422) 'PATCH rechaza un estado inválido (HTTP 422)'

    $invalidField = Invoke-ApiRequest -Method 'PATCH' -Uri "$baseUrl/$directorId" -Body @{ descripcion = 'No existe' }
    Assert-Check ($invalidField.StatusCode -eq 422) 'PATCH rechaza campos inexistentes'

    $delete = Invoke-ApiRequest -Method 'DELETE' -Uri "$baseUrl/$directorId"
    Assert-Check ($delete.StatusCode -eq 204) 'DELETE elimina el director temporal (HTTP 204)'
    $created = $false

    $getDeleted = Invoke-ApiRequest -Method 'GET' -Uri "$baseUrl/$directorId"
    Assert-Check ($getDeleted.StatusCode -eq 404) 'GET devuelve 404 después de eliminar'

    $protectedDelete = Invoke-ApiRequest -Method 'DELETE' -Uri "$baseUrl/1"
    Assert-Check ($protectedDelete.StatusCode -eq 409) 'DELETE protege directores asociados a películas (HTTP 409)'

    Write-Output "Pruebas de directores completadas correctamente: $checks comprobaciones."
}
finally {
    if ($created -and $null -ne $directorId) {
        try {
            $cleanup = Invoke-ApiRequest -Method 'DELETE' -Uri "$baseUrl/$directorId"
            if ($cleanup.StatusCode -ne 204 -and $cleanup.StatusCode -ne 404) {
                Write-Warning "No se pudo limpiar el director temporal con ID $directorId."
            }
            else {
                Write-Output 'Se limpió el director temporal.'
            }
        }
        catch {
            Write-Warning "No se pudo limpiar el director temporal con ID $directorId. Detalle: $_"
        }
    }

    $client.Dispose()
}
