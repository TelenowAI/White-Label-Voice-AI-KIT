<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * Real images for the tests, made without GD (the CI and the database runs have no GD; the kit itself needs none — it reads an image with
 * PHP's own getimagesize): png() builds a PNG of any size from zlib and a CRC; jpeg(), webp() and gif() are tiny images made once with GD
 * (48x36, 32x32, 64x64), kept here as their bytes. file() wraps bytes as an upload under any name (the name never says what the bytes are).
 */
final class TestImages
{
    /** A PNG of this size: a flat colour, or noise (which does not compress, so it is as heavy as its size). */
    public static function png(int $width, int $height, bool $noise = false): string
    {
        $rows = "";
        for ($y = 0; $y < $height; $y++) {
            $rows .= "\0".($noise ? random_bytes($width * 3) : str_repeat("\x25\x63\xeb", $width));
        }
        $chunk = fn (string $type, string $data) => pack("N", strlen($data)).$type.$data.pack("N", crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk("IHDR", pack("NNCCCCC", $width, $height, 8, 2, 0, 0, 0)).$chunk("IDAT", (string) gzcompress($rows)).$chunk("IEND", "");
    }

    /** A 48x36 JPEG. */
    public static function jpeg(): string
    {
        return base64_decode("/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gNDAK/9sAQwAUDg8SDw0UEhASFxUUGB4yIR4cHB49LC4kMklATEtHQEZFUFpzYlBVbVZFRmSIZW13e4GCgU5gjZeMfZZzfoF8/9sAQwEVFxceGh47ISE7fFNGU3x8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8/8AAEQgAJAAwAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8AfpWmf2j5v77y/Lx/DnOc+/tWh/wjP/T3/wCQv/r0eFv+Xr/gH/s1dBXbXr1I1GkzCnTi43Zz/wDwjP8A09/+Qv8A69H/AAjP/T3/AOQv/r10FFY/Wavcv2UOxz//AAjP/T3/AOQv/r1n6rpn9neV++8zzM/w4xjHv712Fc/4p/5df+B/+y1tQr1JVEmyKlOKjdB4W/5ev+Af+zV0Fc/4W/5ev+Af+zV0FY4n+Ky6XwIKKKKwNArn/FP/AC6/8D/9lroK5/xT/wAuv/A//Za3w38VGdX4Gc/RRRXrnGFFFFABRRRQB//Z");
    }

    /** A 32x32 WebP. */
    public static function webp(): string
    {
        return base64_decode("UklGRqoAAABXRUJQVlA4IJ4AAACQBgCdASogACAAAUAmJbAC38UE8n+AUwHyBYgP4BzgGh/9H/6IH9V3lL9r/hH8kAGwALKCpHcivkvwSfOAAP7r0F/9NDb/V4ezkyLXKbjzStBdPfqv55/xdSbuxHa7fHJJ+Ec6Iep4LiNXUwFbt/9i9/+5oC5LqbF804P4LiNXUwGrhcJ0Jza33wWelgGwVHIqFIF/hh34ZGURPYgAAA==");
    }

    /** A 64x64 GIF (a type the kit refuses). */
    public static function gif(): string
    {
        return base64_decode("R0lGODdhQABAAIAAACRi7Pz+/CwAAAAAQABAAAACpYSPqcvtD6OctNqLs16h+w+G4kiWAWOm6hqi7AuLbkzDc42nd87LXA9s/YLE07AI3CFzymWt6YxBo68pdWW96o5aG7fLyoJH4rFQYfai0+Evm1R+G9dyU/x9Z+fTe3N//AcW2DWoVXh1SJUYtejUuPSIFFk0SVQZdJnkVgeS2ePJA8q0yekhinP6RFo6l8AKt1qaSjMrFctZq7axy9vr+wscLCxRAAA7");
    }

    public static function file(string $name, string $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }
}
