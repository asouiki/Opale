<?php

namespace App\Support;

/**
 * JsonFile
 *
 * Passerelle centralisée d'entrées/sorties JSON sur fichier : lecture décodée et écriture atomique.
 */
final class JsonFile
{
    /**
     * Lit et décode un fichier JSON en tableau associatif.
     *
     * Retourne [] si le fichier est absent, illisible, vide, ou si son contenu
     * n'est pas un objet/tableau JSON valide.
     *
     * @return array<array-key, mixed>
     */
    public static function read(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Écrit un contenu (déjà encodé) sur disque de façon ATOMIQUE.
     *
     * L'appelant reste responsable de l'encodage JSON (flags/format propres à son cache) : on ne
     * centralise que le mécanisme d'écriture. Le répertoire parent est créé au besoin, puis le contenu
     * est écrit dans un fichier temporaire renommé (`rename` atomique sur le même système de fichiers)
     * → jamais de fichier cache tronqué en cas d'interruption ou d'écriture concurrente.
     *
     * @param  string $path    Chemin cible du fichier
     * @param  string $content Contenu déjà sérialisé (JSON encodé par l'appelant)
     * @return bool            true si l'écriture a abouti, false sinon (l'appelant décide throw/silence)
     */
    public static function write(string $path, string $content): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        // Fichier temporaire dans le même répertoire (donc même FS → rename atomique).
        $tmp = $path . '.tmp.' . uniqid('', true);
        if (file_put_contents($tmp, $content) === false) {
            return false;
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }
}
