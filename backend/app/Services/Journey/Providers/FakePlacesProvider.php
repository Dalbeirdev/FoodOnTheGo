<?php

namespace App\Services\Journey\Providers;

use App\Contracts\Journey\PlacesProvider;
use App\Enums\ProviderFailure;
use App\Exceptions\ProviderException;
use App\Services\Journey\Data\PlaceSearchScope;
use App\Services\Journey\Data\PlaceSuggestion;
use App\Services\Journey\Data\ResolvedPlace;
use App\Support\Geo\Location;
use Illuminate\Support\Str;

/**
 * DEVELOPMENT places provider: a fixed, deterministic list of Indian cities, stations, airports and landmarks
 * (plus a few places abroad, so "outside the market" can be exercised). No network, no credentials, no cost.
 * Results are restricted to the scope's country like a real provider would be configured.
 *
 * Tests script failures with failNext(). The query "nowhere" never matches. Never acceptable in production
 * (ProviderManager refuses it).
 */
final class FakePlacesProvider implements PlacesProvider
{
    public const NAME = 'fake';

    /** id => [name, formatted address, kind, lat, lng, country, timezone, region, locality, aliases] */
    public const PLACES = [
        'noida-62' => ['Sector 62, Noida', 'Sector 62, Noida, Uttar Pradesh 201309, India', 'landmark', 28.628, 77.3649, 'IN', 'Asia/Kolkata', 'Uttar Pradesh', 'Noida', []],
        'noida-18' => ['Sector 18, Noida', 'Sector 18, Noida, Uttar Pradesh 201301, India', 'landmark', 28.5708, 77.3261, 'IN', 'Asia/Kolkata', 'Uttar Pradesh', 'Noida', ['atta market']],
        'noida' => ['Noida', 'Noida, Uttar Pradesh, India', 'city', 28.5355, 77.391, 'IN', 'Asia/Kolkata', 'Uttar Pradesh', 'Noida', []],
        'delhi' => ['Delhi', 'National Capital Territory of Delhi, India', 'city', 28.6139, 77.209, 'IN', 'Asia/Kolkata', 'Delhi', 'New Delhi', ['new delhi']],
        'cp-delhi' => ['Connaught Place', 'Connaught Place, New Delhi, Delhi 110001, India', 'landmark', 28.6315, 77.2167, 'IN', 'Asia/Kolkata', 'Delhi', 'New Delhi', ['cp']],
        'ndls' => ['New Delhi Railway Station', 'Paharganj, New Delhi, Delhi 110055, India', 'station', 28.6428, 77.2197, 'IN', 'Asia/Kolkata', 'Delhi', 'New Delhi', ['ndls']],
        'igi' => ['Indira Gandhi International Airport', 'New Delhi, Delhi 110037, India', 'airport', 28.5562, 77.1, 'IN', 'Asia/Kolkata', 'Delhi', 'New Delhi', ['igi', 'delhi airport']],
        'gurugram' => ['Gurugram', 'Gurugram, Haryana, India', 'city', 28.4595, 77.0266, 'IN', 'Asia/Kolkata', 'Haryana', 'Gurugram', ['gurgaon']],
        'cyber-city' => ['Cyber City, Gurugram', 'DLF Cyber City, Gurugram, Haryana 122002, India', 'landmark', 28.4949, 77.0895, 'IN', 'Asia/Kolkata', 'Haryana', 'Gurugram', []],
        'ghaziabad' => ['Ghaziabad', 'Ghaziabad, Uttar Pradesh, India', 'city', 28.6692, 77.4538, 'IN', 'Asia/Kolkata', 'Uttar Pradesh', 'Ghaziabad', []],
        'panipat' => ['Panipat', 'Panipat, Haryana, India', 'city', 29.3909, 76.9635, 'IN', 'Asia/Kolkata', 'Haryana', 'Panipat', []],
        'karnal' => ['Karnal', 'Karnal, Haryana, India', 'city', 29.6857, 76.9905, 'IN', 'Asia/Kolkata', 'Haryana', 'Karnal', []],
        'ambala' => ['Ambala', 'Ambala, Haryana, India', 'city', 30.3782, 76.7767, 'IN', 'Asia/Kolkata', 'Haryana', 'Ambala', ['ambala cantt']],
        'chandigarh' => ['Chandigarh', 'Chandigarh, India', 'city', 30.7333, 76.7794, 'IN', 'Asia/Kolkata', 'Chandigarh', 'Chandigarh', []],
        'chandigarh-rly' => ['Chandigarh Railway Station', 'Daria, Chandigarh 160002, India', 'station', 30.7046, 76.8213, 'IN', 'Asia/Kolkata', 'Chandigarh', 'Chandigarh', []],
        'chandigarh-apt' => ['Chandigarh Airport', 'Shaheed Bhagat Singh International Airport, Chandigarh, India', 'airport', 30.6735, 76.7885, 'IN', 'Asia/Kolkata', 'Chandigarh', 'Chandigarh', ['ixc']],
        'rupnagar' => ['Rupnagar', 'Rupnagar, Punjab 140001, India', 'city', 30.9685, 76.5265, 'IN', 'Asia/Kolkata', 'Punjab', 'Rupnagar', ['ropar']],
        'hoshiarpur' => ['Hoshiarpur', 'Hoshiarpur, Punjab 146001, India', 'city', 31.5273, 75.9115, 'IN', 'Asia/Kolkata', 'Punjab', 'Hoshiarpur', []],
        'pathankot' => ['Pathankot', 'Pathankot, Punjab 145001, India', 'city', 32.2643, 75.6421, 'IN', 'Asia/Kolkata', 'Punjab', 'Pathankot', []],
        'ludhiana' => ['Ludhiana', 'Ludhiana, Punjab, India', 'city', 30.901, 75.8573, 'IN', 'Asia/Kolkata', 'Punjab', 'Ludhiana', []],
        'amritsar' => ['Amritsar', 'Amritsar, Punjab, India', 'city', 31.634, 74.8723, 'IN', 'Asia/Kolkata', 'Punjab', 'Amritsar', []],
        'jammu' => ['Jammu', 'Jammu, Jammu and Kashmir, India', 'city', 32.7266, 74.857, 'IN', 'Asia/Kolkata', 'Jammu and Kashmir', 'Jammu', []],
        'jammu-tawi' => ['Jammu Tawi Railway Station', 'Jammu, Jammu and Kashmir 180012, India', 'station', 32.7099, 74.8631, 'IN', 'Asia/Kolkata', 'Jammu and Kashmir', 'Jammu', []],
        'shimla' => ['Shimla', 'Shimla, Himachal Pradesh, India', 'city', 31.1048, 77.1734, 'IN', 'Asia/Kolkata', 'Himachal Pradesh', 'Shimla', []],
        'dehradun' => ['Dehradun', 'Dehradun, Uttarakhand, India', 'city', 30.3165, 78.0322, 'IN', 'Asia/Kolkata', 'Uttarakhand', 'Dehradun', []],
        'agra' => ['Agra', 'Agra, Uttar Pradesh, India', 'city', 27.1767, 78.0081, 'IN', 'Asia/Kolkata', 'Uttar Pradesh', 'Agra', []],
        'mathura' => ['Mathura', 'Mathura, Uttar Pradesh, India', 'city', 27.4924, 77.6737, 'IN', 'Asia/Kolkata', 'Uttar Pradesh', 'Mathura', []],
        'lucknow' => ['Lucknow', 'Lucknow, Uttar Pradesh, India', 'city', 26.8467, 80.9462, 'IN', 'Asia/Kolkata', 'Uttar Pradesh', 'Lucknow', []],
        'jaipur' => ['Jaipur', 'Jaipur, Rajasthan, India', 'city', 26.9124, 75.7873, 'IN', 'Asia/Kolkata', 'Rajasthan', 'Jaipur', []],
        'jaipur-rly' => ['Jaipur Junction', 'Jaipur Junction Railway Station, Jaipur, Rajasthan 302006, India', 'station', 26.9196, 75.7878, 'IN', 'Asia/Kolkata', 'Rajasthan', 'Jaipur', []],
        'udaipur' => ['Udaipur', 'Udaipur, Rajasthan, India', 'city', 24.5854, 73.7125, 'IN', 'Asia/Kolkata', 'Rajasthan', 'Udaipur', []],
        'ahmedabad' => ['Ahmedabad', 'Ahmedabad, Gujarat, India', 'city', 23.0225, 72.5714, 'IN', 'Asia/Kolkata', 'Gujarat', 'Ahmedabad', []],
        'vadodara' => ['Vadodara', 'Vadodara, Gujarat, India', 'city', 22.3072, 73.1812, 'IN', 'Asia/Kolkata', 'Gujarat', 'Vadodara', ['baroda']],
        'surat' => ['Surat', 'Surat, Gujarat, India', 'city', 21.1702, 72.8311, 'IN', 'Asia/Kolkata', 'Gujarat', 'Surat', []],
        'vapi' => ['Vapi', 'Vapi, Gujarat, India', 'city', 20.3893, 72.9106, 'IN', 'Asia/Kolkata', 'Gujarat', 'Vapi', []],
        'mumbai' => ['Mumbai', 'Mumbai, Maharashtra, India', 'city', 19.076, 72.8777, 'IN', 'Asia/Kolkata', 'Maharashtra', 'Mumbai', ['bombay']],
        'pune' => ['Pune', 'Pune, Maharashtra, India', 'city', 18.5204, 73.8567, 'IN', 'Asia/Kolkata', 'Maharashtra', 'Pune', []],
        'bengaluru' => ['Bengaluru', 'Bengaluru, Karnataka, India', 'city', 12.9716, 77.5946, 'IN', 'Asia/Kolkata', 'Karnataka', 'Bengaluru', ['bangalore']],
        'mysuru' => ['Mysuru', 'Mysuru, Karnataka, India', 'city', 12.2958, 76.6394, 'IN', 'Asia/Kolkata', 'Karnataka', 'Mysuru', ['mysore']],
        'hyderabad' => ['Hyderabad', 'Hyderabad, Telangana, India', 'city', 17.385, 78.4867, 'IN', 'Asia/Kolkata', 'Telangana', 'Hyderabad', []],
        'chennai' => ['Chennai', 'Chennai, Tamil Nadu, India', 'city', 13.0827, 80.2707, 'IN', 'Asia/Kolkata', 'Tamil Nadu', 'Chennai', ['madras']],
        'kolkata' => ['Kolkata', 'Kolkata, West Bengal, India', 'city', 22.5726, 88.3639, 'IN', 'Asia/Kolkata', 'West Bengal', 'Kolkata', ['calcutta']],
        'port-blair' => ['Port Blair', 'Port Blair, Andaman and Nicobar Islands, India', 'city', 11.6234, 92.7265, 'IN', 'Asia/Kolkata', 'Andaman and Nicobar Islands', 'Port Blair', []],
        // Outside the launch market (controlled test data — never a product default)
        'dubai' => ['Dubai', 'Dubai, United Arab Emirates', 'city', 25.2048, 55.2708, 'AE', 'Asia/Dubai', 'Dubai', 'Dubai', []],
        'abu-dhabi' => ['Abu Dhabi', 'Abu Dhabi, United Arab Emirates', 'city', 24.4539, 54.3773, 'AE', 'Asia/Dubai', 'Abu Dhabi', 'Abu Dhabi', []],
        'london' => ['London', 'London, United Kingdom', 'city', 51.5074, -0.1278, 'GB', 'Europe/London', 'Greater London', 'London', []],
        'paris' => ['Paris', 'Paris, France', 'city', 48.8566, 2.3522, 'FR', 'Europe/Paris', 'Île-de-France', 'Paris', []],
        'kathmandu' => ['Kathmandu', 'Kathmandu, Nepal', 'city', 27.7172, 85.324, 'NP', 'Asia/Kathmandu', 'Bagmati', 'Kathmandu', []],
        'lahore' => ['Lahore', 'Lahore, Punjab, Pakistan', 'city', 31.5204, 74.3587, 'PK', 'Asia/Karachi', 'Punjab', 'Lahore', []],
    ];

    /** @var list<ProviderFailure> */
    private array $failNext = [];

    public int $searches = 0;

    public int $resolutions = 0;

    public function name(): string
    {
        return self::NAME;
    }

    public function isFake(): bool
    {
        return true;
    }

    public function failNext(ProviderFailure $failure, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->failNext[] = $failure;
        }
    }

    public function search(string $query, PlaceSearchScope $scope): array
    {
        $this->consumeFailure();
        $this->searches++;
        $q = self::normalize($query);
        if (mb_strlen($q) < 2 || $q === 'nowhere') {
            return [];
        }

        $scored = [];
        foreach (self::PLACES as $id => [$name, $address, $kind, , , $country, , $region, $locality, $aliases]) {
            if ($country !== $scope->countryCode) {
                continue; // the market's country is a hard boundary
            }
            $names = [self::normalize($name), ...array_map(self::normalize(...), $aliases)];
            $score = match (true) {
                in_array($q, $names, true) => 0,
                (bool) array_filter($names, fn (string $n): bool => str_starts_with($n, $q)) => 1,
                (bool) array_filter($names, fn (string $n): bool => str_contains($n, $q)) || str_contains(self::normalize($address), $q) => 2,
                default => -1,
            };
            if ($score >= 0) {
                $scored[] = [$score, $id, new PlaceSuggestion(self::NAME, 'fake:'.$id, $name, $address, $country, $kind, $region, $locality)];
            }
        }
        usort($scored, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(fn (array $row): PlaceSuggestion => $row[2], array_slice($scored, 0, max(1, $scope->limit)));
    }

    public function resolve(string $placeId, PlaceSearchScope $scope): ResolvedPlace
    {
        $this->consumeFailure();
        $this->resolutions++;
        $id = str_starts_with($placeId, 'fake:') ? substr($placeId, 5) : null;
        $place = $id !== null ? (self::PLACES[$id] ?? null) : null;
        if ($place === null) {
            throw ProviderException::placeNotFound(self::NAME);
        }
        [$name, $address, $kind, $lat, $lng, $country, $timezone, $region, $locality] = $place;

        return new ResolvedPlace(self::NAME, $placeId, $name, new Location($lat, $lng, $country, $address, $placeId, $region, $locality, null, $timezone), $kind);
    }

    private function consumeFailure(): void
    {
        $failure = array_shift($this->failNext);
        if ($failure !== null) {
            throw new ProviderException($failure, self::NAME);
        }
    }

    private static function normalize(string $value): string
    {
        return trim(mb_strtolower(Str::ascii($value)));
    }
}
