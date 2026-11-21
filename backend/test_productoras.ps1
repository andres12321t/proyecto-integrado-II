$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.Net.Http

$baseUrl = 'http://127.0.0.1:8000/api/productoras'
$client = New-Object System.Net.Http.HttpClient
$productoraId = $null
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
    ) 'GET lista las productoras existentes'

    $name = 'Prueba VS Code ' + [guid]::NewGuid().ToString('N')
    $productora = @{
        nombre = $name
        slogan = 'Slogan temporal'
        descripcion = 'Productora temporal creada para probar el CRUD.'
        estado = 'Activo'
    }

    $post = Invoke-ApiRequest -Method 'POST' -Uri $baseUrl -Body $productora
    Assert-Check ($post.StatusCode -eq 201 -and $null -ne $post.Data.data.id) 'POST crea una productora (HTTP 201)'
    $productoraId = [int]$post.Data.data.id
    $created = $true
    Assert-Check (
        $post.Data.data.nombre -eq $name -and
        $post.Data.data.slogan -eq $productora.slogan -and
        $post.Data.data.estado -eq 'Activo'
    ) 'POST devuelve los campos de la productora'

    $duplicate = Invoke-ApiRequest -Method 'POST' -Uri $baseUrl -Body $productora
    Assert-Check ($duplicate.StatusCode -eq 409) 'POST rechaza un nombre duplicado (HTTP 409)'

    $get = Invoke-ApiRequest -Method 'GET' -Uri "$baseUrl/$productoraId"
    Assert-Check ($get.StatusCode -eq 200 -and $get.Data.data.nombre -eq $name) 'GET por ID devuelve la productora'

    $replacement = @{
        nombre = "$name actualizada"
        slogan = 'Slogan actualizado'
        descripcion = 'Descripción reemplazada mediante PUT.'
        estado = 'Inactivo'
    }
    $put = Invoke-ApiRequest -Method 'PUT' -Uri "$baseUrl/$productoraId" -Body $replacement
    Assert-Check (
        $put.StatusCode -eq 200 -and
        $put.Data.data.nombre -eq $replacement.nombre -and
        $put.Data.data.estado -eq 'Inactivo'
    ) 'PUT reemplaza los campos de la productora'

    $patch = Invoke-ApiRequest -Method 'PATCH' -Uri "$baseUrl/$productoraId" -Body @{ estado = 'Activo' }
    Assert-Check (
        $patch.StatusCode -eq 200 -and
        $patch.Data.data.estado -eq 'Activo' -and
        $patch.Data.data.slogan -eq $replacement.slogan
    ) 'PATCH modifica un campo y conserva los demás'

    $invalidState = Invoke-ApiRequest -Method 'PATCH' -Uri "$baseUrl/$productoraId" -Body @{ estado = 'Pendiente' }
    Assert-Check ($invalidState.StatusCode -eq 422) 'PATCH rechaza un estado inválido (HTTP 422)'

    $invalidField = Invoke-ApiRequest -Method 'PATCH' -Uri "$baseUrl/$productoraId" -Body @{ tipo = 'Estudio' }
    Assert-Check ($invalidField.StatusCode -eq 422) 'PATCH rechaza campos inexistentes'

    $delete = Invoke-ApiRequest -Method 'DELETE' -Uri "$baseUrl/$productoraId"
    Assert-Check ($delete.StatusCode -eq 204) 'DELETE elimina la productora temporal (HTTP 204)'
    $created = $false

    $getDeleted = Invoke-ApiRequest -Method 'GET' -Uri "$baseUrl/$productoraId"
    Assert-Check ($getDeleted.StatusCode -eq 404) 'GET devuelve 404 después de eliminar'

    $protectedDelete = Invoke-ApiRequest -Method 'DELETE' -Uri "$baseUrl/1"
    Assert-Check ($protectedDelete.StatusCode -eq 409) 'DELETE protege productoras asociadas a películas (HTTP 409)'

    $defaultName = 'Predeterminada ' + [guid]::NewGuid().ToString('N')
    $defaults = Invoke-ApiRequest -Method 'POST' -Uri $baseUrl -Body @{ nombre = $defaultName }
    $defaultId = [int]$defaults.Data.data.id
    Assert-Check (
        $defaults.StatusCode -eq 201 -and
        $defaults.Data.data.estado -eq 'Activo' -and
        $null -eq $defaults.Data.data.slogan -and
        $null -eq $defaults.Data.data.descripcion
    ) 'POST asigna valores opcionales predeterminados'
    $defaultDelete = Invoke-ApiRequest -Method 'DELETE' -Uri "$baseUrl/$defaultId"
    Assert-Check ($defaultDelete.StatusCode -eq 204) 'DELETE elimina el registro de prueba de valores predeterminados'

    Write-Output "Pruebas de productoras completadas correctamente: $checks comprobaciones."
}
finally {
    if ($created -and $null -ne $productoraId) {
        try {
            $cleanup = Invoke-ApiRequest -Method 'DELETE' -Uri "$baseUrl/$productoraId"
            if ($cleanup.StatusCode -ne 204 -and $cleanup.StatusCode -ne 404) {
                Write-Warning "No se pudo limpiar la productora temporal con ID $productoraId."
            }
            else {
                Write-Output 'Se limpió la productora temporal.'
            }
        }
        catch {
            Write-Warning "No se pudo limpiar la productora temporal con ID $productoraId. Detalle: $_"
        }
    }

    $client.Dispose()
}
