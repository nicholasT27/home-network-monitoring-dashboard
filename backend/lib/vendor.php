<?php
// Small built-in OUI (first 3 MAC bytes) table of common home-network manufacturers.
// Extend it, or swap in the full IEEE list, if more detail is needed.

function oui_table(): array
{
    return [
        'B8:27:EB' => 'Raspberry Pi', 'DC:A6:32' => 'Raspberry Pi', 'E4:5F:01' => 'Raspberry Pi',
        '3C:5A:B4' => 'Google', 'F4:F5:D8' => 'Google', '00:1A:11' => 'Google', '18:B4:30' => 'Google Nest',
        '44:65:0D' => 'Amazon', 'FC:65:DE' => 'Amazon', '68:54:FD' => 'Amazon',
        '24:0A:C4' => 'Espressif (IoT)', '30:AE:A4' => 'Espressif (IoT)', '84:0D:8E' => 'Espressif (IoT)',
        '00:17:88' => 'Philips Hue', '00:03:93' => 'Apple', '00:1C:B3' => 'Apple', '3C:07:54' => 'Apple',
        '00:1B:63' => 'Apple', 'F0:18:98' => 'Apple', 'A4:83:E7' => 'Apple',
        '00:16:6C' => 'Samsung', '00:12:FB' => 'Samsung', '00:1D:25' => 'Samsung', '8C:77:12' => 'Samsung',
        '98:DA:C4' => 'TP-Link', '50:C7:BF' => 'TP-Link', 'F4:F2:6D' => 'TP-Link',
        '28:6C:07' => 'Xiaomi', '64:09:80' => 'Xiaomi',
        '00:23:14' => 'Intel', '3C:97:0E' => 'Intel', '00:1F:3B' => 'Intel',
        '00:1E:C9' => 'Dell', '00:21:5A' => 'HP', '00:09:5B' => 'Netgear', '00:14:6C' => 'Netgear',
        '00:1D:60' => 'ASUS', '04:D4:C4' => 'ASUS', '00:04:4B' => 'NVIDIA',
        '00:0D:3A' => 'Microsoft', '00:15:5D' => 'Microsoft (Hyper-V)',
        '00:50:56' => 'VMware', '08:00:27' => 'VirtualBox',
    ];
}

// IEEE 802: bit 1 of the first octet marks a locally administered (e.g. randomised) address.
function is_private_mac(string $mac): bool
{
    return (hexdec(substr($mac, 0, 2)) & 0x02) !== 0;
}

function vendor_for_mac(string $mac): ?string
{
    if (is_private_mac($mac)) {
        return 'Private (randomised) MAC';
    }
    return oui_table()[substr($mac, 0, 8)] ?? null;
}
