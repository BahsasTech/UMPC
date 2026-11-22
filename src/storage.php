<?php
declare(strict_types=1);

/**
 * File storage abstraction: local public/uploads or Supabase Storage.
 * Supabase buckets should be public for photo/product images.
 */

/** Bucket/key for a storage kind: photos or products. */
function storage_bucket(string $kind): string
{
    $key = $kind === 'photos' ? 'bucket_photos' : 'bucket_products';
    return (string) config('storage.supabase.' . $key, 'umpcsl-' . $kind);
}

/** Local directory for a storage kind. */
function storage_local_dir(string $kind): string
{
    return (string) config($kind === 'photos' ? 'uploads.photos_dir' : 'uploads.products_dir');
}

/** Store an uploaded file and return the stored object name. */
function storage_put(string $kind, string $name, string $tmpFile, string $contentType): string
{
    if (config('storage.driver') === 'supabase') {
        $base = rtrim((string) config('storage.supabase.url'), '/');
        $key = (string) config('storage.supabase.service_role_key');
        if ($base === '' || $key === '') {
            throw new RuntimeException('Supabase Storage is not configured (SUPABASE_URL / SUPABASE_SERVICE_ROLE_KEY).');
        }
        $body = file_get_contents($tmpFile);
        if ($body === false) {
            throw new RuntimeException('Could not read the uploaded file.');
        }

        $url = $base . '/storage/v1/object/' . rawurlencode(storage_bucket($kind)) . '/' . rawurlencode($name);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'apikey: ' . $key,
                'Content-Type: ' . $contentType,
                'x-upsert: true',
                'cache-control: 3600',
            ],
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Supabase Storage upload failed: ' . ($error ?: substr((string) $response, 0, 200)));
        }
        return $name;
    }

    $dir = storage_local_dir($kind);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $dest = $dir . DIRECTORY_SEPARATOR . $name;
    $moved = is_uploaded_file($tmpFile)
        ? move_uploaded_file($tmpFile, $dest)
        : (copy($tmpFile, $dest) && @unlink($tmpFile));
    if (!$moved) {
        throw new RuntimeException('Could not store the uploaded file.');
    }
    return $name;
}

/** Public URL for a stored object, or null when there is no object. */
function storage_url(string $kind, ?string $name): ?string
{
    if ($name === null || $name === '') {
        return null;
    }
    if (config('storage.driver') === 'supabase') {
        $base = rtrim((string) config('storage.supabase.url'), '/');
        if ($base === '') {
            return null;
        }
        return $base . '/storage/v1/object/public/' . rawurlencode(storage_bucket($kind)) . '/' . rawurlencode($name);
    }
    $path = storage_local_dir($kind) . DIRECTORY_SEPARATOR . $name;
    return is_file($path) ? '/uploads/' . $kind . '/' . rawurlencode($name) : null;
}

/** Delete a stored object (best effort). */
function storage_delete(string $kind, ?string $name): void
{
    if ($name === null || $name === '') {
        return;
    }
    if (config('storage.driver') === 'supabase') {
        $base = rtrim((string) config('storage.supabase.url'), '/');
        $key = (string) config('storage.supabase.service_role_key');
        if ($base === '' || $key === '') {
            return;
        }
        $url = $base . '/storage/v1/object/' . rawurlencode(storage_bucket($kind)) . '/' . rawurlencode($name);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'apikey: ' . $key,
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);
        return;
    }
    $path = storage_local_dir($kind) . DIRECTORY_SEPARATOR . $name;
    if (is_file($path)) {
        @unlink($path);
    }
}
