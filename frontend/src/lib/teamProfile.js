export const squadGroups = [
  { id: 'goalkeeper', label: 'Porteros' },
  { id: 'defence', label: 'Defensas' },
  { id: 'midfield', label: 'Centrocampistas' },
  { id: 'offence', label: 'Delanteros' },
  { id: 'other', label: 'Otros' },
];

export function positionGroup(position) {
  if (/goalkeeper/i.test(position || '')) return 'goalkeeper';
  if (/defence|defender|back/i.test(position || '')) return 'defence';
  if (/midfield/i.test(position || '')) return 'midfield';
  if (/offence|forward|attack|winger|striker/i.test(position || '')) return 'offence';
  return 'other';
}

function profileFields(source) {
  const coach = source.coach;
  return {
    ...source,
    short_name: source.short_name ?? source.shortName ?? null,
    club_colors: source.club_colors ?? source.clubColors ?? null,
    source_updated_at: source.source_updated_at ?? source.lastUpdated ?? null,
    running_competitions: source.running_competitions ?? source.runningCompetitions ?? [],
    coach: coach ? {
      ...coach,
      date_of_birth: coach.date_of_birth ?? coach.dateOfBirth ?? null,
      name: coach.name || [coach.first_name ?? coach.firstName, coach.last_name ?? coach.lastName].filter(Boolean).join(' '),
    } : null,
  };
}

function squad(players) {
  return (players || []).map(player => ({
    ...player,
    date_of_birth: player.date_of_birth ?? player.dateOfBirth ?? null,
    shirt_number: player.shirt_number ?? player.shirtNumber ?? null,
    position: player.position || player.section || null,
  })).sort((first, second) => first.name.localeCompare(second.name, 'es', { sensitivity: 'base' }) || first.id - second.id);
}

export function teamProfile(payload) {
  const team = profileFields(payload.team || payload);
  const rosters = (payload.participations || []).map(participation => ({
    ...participation,
    key: participation.competition.id + ':' + participation.season.id,
    profile: profileFields(participation.profile || {}),
    players: squad(participation.players),
  }));
  if (Array.isArray(team.squad)) {
    rosters.unshift({ key: 'current', competition: null, season: null, name: team.name, crest: team.crest, profile: team, players: squad(team.squad), last_updated_at: team.last_updated_at });
  }
  return { team, rosters };
}

export function selectedTeam(profile, roster) {
  if (!roster) return profile.team;
  return {
    ...profile.team,
    ...roster.profile,
    id: profile.team.id,
    name: roster.name || profile.team.name,
    short_name: roster.short_name || roster.profile.short_name || profile.team.short_name,
    crest: roster.crest || profile.team.crest,
    area: roster.profile.area || profile.team.area,
    source_updated_at: roster.profile.source_updated_at || profile.team.source_updated_at,
  };
}

export function teamCompetitions(profile, team) {
  const competitions = new Map();
  for (const competition of [...(team.running_competitions || []), ...profile.rosters.map(roster => roster.competition)]) {
    if (competition?.id && competition.name) competitions.set(competition.id, competition);
  }
  return [...competitions.values()];
}
