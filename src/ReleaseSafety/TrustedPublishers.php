<?php

declare(strict_types=1);

namespace Kighlander\GeoPlzDetails\ReleaseSafety;

/**
 * D.45 PREVIEW. No production release signing authority has been approved.
 * Insert public keys ONLY after separate ownership/legal review and an
 * explicit package change approval. NEVER store publisher private keys here.
 * Map string key_id => base64-encoded Ed25519 public key (32 bytes).
 */
final class TrustedPublishers
{
    public const KEYS = [];
}
