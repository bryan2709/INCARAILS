<?php

namespace App\Services\Imports;

class TeamProfileMapper
{
    public function map(array $team): array
    {
        $area = is_array($team['area'] ?? null) ? $team['area'] : null;

        return [
            'tla' => $this->text($team['tla'] ?? null),
            'source_updated_at' => $this->text($team['lastUpdated'] ?? null),
            'area' => $area ? [
                'id' => $this->positiveInteger($area['id'] ?? null),
                'name' => $this->text($area['name'] ?? null),
                'code' => $this->text($area['code'] ?? null),
                'flag' => $this->text($area['flag'] ?? null),
            ] : null,
            'address' => $this->text($team['address'] ?? null),
            'website' => $this->text($team['website'] ?? null),
            'founded' => $this->positiveInteger($team['founded'] ?? null),
            'club_colors' => $this->text($team['clubColors'] ?? null),
            'venue' => $this->text($team['venue'] ?? null),
            'coach' => $this->person($team['coach'] ?? null),
            'running_competitions' => $this->competitions($team['runningCompetitions'] ?? null),
        ];
    }

    private function person(mixed $person): ?array
    {
        if (! is_array($person) || ! $this->text($person['name'] ?? null)) {
            return null;
        }

        $contract = is_array($person['contract'] ?? null) ? $person['contract'] : [];

        return [
            'id' => $this->positiveInteger($person['id'] ?? null),
            'name' => $this->text($person['name']),
            'first_name' => $this->text($person['firstName'] ?? null),
            'last_name' => $this->text($person['lastName'] ?? null),
            'date_of_birth' => $this->text($person['dateOfBirth'] ?? null),
            'nationality' => $this->text($person['nationality'] ?? null),
            'contract' => [
                'start' => $this->text($contract['start'] ?? null),
                'until' => $this->text($contract['until'] ?? null),
            ],
        ];
    }

    private function competitions(mixed $competitions): array
    {
        $result = [];
        foreach (is_array($competitions) ? $competitions : [] as $competition) {
            if (! is_array($competition) || ! $this->positiveInteger($competition['id'] ?? null) || ! $this->text($competition['name'] ?? null)) {
                continue;
            }

            $id = $this->positiveInteger($competition['id']);
            $result[$id] = [
                'id' => $id,
                'name' => $this->text($competition['name']),
                'code' => $this->text($competition['code'] ?? null),
                'type' => $this->text($competition['type'] ?? null),
                'emblem' => $this->text($competition['emblem'] ?? null),
            ];
        }

        return array_values($result);
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $integer === false ? null : $integer;
    }
}
