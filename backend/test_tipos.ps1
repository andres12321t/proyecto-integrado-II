$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.Net.Http

$baseUrl = 'http://127.0.0.1:8000/api/tipos'
$client = New-Object System.Net.Http.HttpClient
$tipoId = $null
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
    ) 'GET lista los tipos existentes'

    $name = 'Prueba VS Code ' + [guid]::NewGuid().ToString('N').Substring(0, 20)
    $tipo = @{
        nombre = $name
        descripcion = 'Tipo temporal creado para probar el CRUD.'
    }

    $post = Invoke-ApiRequest -Method 'POST' -Uri $baseUrl -Body $tipo
    Assert-Check ($post.StatusCode -eq 201 -and $null -ne $post.Data.data.id) 'POST crea un tipo (HTTP 201)'
    $tipoId = [int]$post.Data.data.id
    $created = $true
    Assert-Check (
        $post.Data.data.nombre -eq $name -and
        $post.Data.data.descripcion -eq $tipo.descripcion
    ) 'POST devuelve los campos del tipo'

    $duplicate = Invoke-ApiRequest -Method 'POST' -Uri $baseUrl -Body $tipo
    Assert-Check ($duplicate.StatusCode -eq 409) 'POST rechaza un nombre duplicado (HTTP 409)'

    $get = Invoke-ApiRequest -Method 'GET' -Uri "$baseUrl/$tipoId"
    Assert-Check ($get.StatusCode -eq 200 -and $get.Data.data.nombre -eq $name) 'GET por ID devuelve el tipo'

    $replacement = @{
        nombre = "$name actualizado"
        descripcion = 'Descripción reemplazada mediante PUT.'
    }
    $put = Invoke-ApiRequest -Method 'PUT' -Uri "$baseUrl/$tipoId" -Body $replacement
    Assert-Check (
        $put.StatusCode -eq 200 -and
        $put.Data.data.nombre -eq $replacement.nombre -and
        $put.Data.data.descripcion -eq $replacement.descripcion
    ) 'PUT reemplaza los campos del tipo'

    $patch = Invoke-ApiRequest -Method 'PATCH' -Uri "$baseUrl/$tipoId" -Body @{ descripcion = 'Descripción PATCH.' }
    Assert-Check (
        $patch.StatusCode -eq 200 -and
        $patch.Data.data.descripcion -eq 'Descripción PATCH.' -and
        $patch.Data.data.nombre -eq $replacement.nombre
    ) 'PATCH modifica la descripción y conserva el nombre'

    $invalidField = Invoke-ApiRequest -Method 'PATCH' -Uri "$baseUrl/$tipoId" -Body @{ estado = 'Activo' }
    Assert-Check ($invalidField.StatusCode -eq 422) 'PATCH rechaza campos que no existen en tipos'

    $delete = Invoke-ApiRequest -Method 'DELETE' -Uri "$baseUrl/$tipoId"
    Assert-Check ($delete.StatusCode -eq 204) 'DELETE elimina el tipo temporal (HTTP 204)'
    $created = $false

    $getDeleted = Invoke-ApiRequest -Method 'GET' -Uri "$baseUrl/$tipoId"
    Assert-Check ($getDeleted.StatusCode -eq 404) 'GET devuelve 404 después de eliminar'

    $protectedDelete = Invoke-ApiRequest -Method 'DELETE' -Uri "$baseUrl/1"
    Assert-Check ($protectedDelete.StatusCode -eq 409) 'DELETE protege tipos asociados a películas (HTTP 409)'

    $defaultName = 'Predeterminado ' + [guid]::NewGuid().ToString('N').Substring(0, 20)
    $defaults = Invoke-ApiRequest -Method 'POST' -Uri $baseUrl -Body @{ nombre = $defaultName }
    $defaultId = [int]$defaults.Data.data.id
    Assert-Check (
        $defaults.StatusCode -eq 201 -and
        $null -eq $defaults.Data.data.descripcion
    ) 'POST asigna null a descripción cuando se omite'
    $defaultDelete = Invoke-ApiRequest -Method 'DELETE' -Uri "$baseUrl/$defaultId"
    Assert-Check ($defaultDelete.StatusCode -eq 204) 'DELETE elimina el tipo de prueba de valores predeterminados'

    Write-Output "Pruebas de tipos completadas correctamente: $checks comprobaciones."
}
finally {
    if ($created -and $null -ne $tipoId) {
        try {
            $cleanup = Invoke-ApiRequest -Method 'DELETE' -Uri "$baseUrl/$tipoId"
            if ($cleanup.StatusCode -ne 204 -and $cleanup.StatusCode -ne 404) {
                Write-Warning "No se pudo limpiar el tipo temporal con ID $tipoId."
            }
            else {
                Write-Output 'Se limpió el tipo temporal.'
            }
        }
        catch {
            Write-Warning "No se pudo limpiar el tipo temporal con ID $tipoId. Detalle: $_"
        }
    }

    $client.Dispose()
}
