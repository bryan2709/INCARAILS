const positionLabels = {
  Goalkeeper: 'Portero',
  Defence: 'Defensa',
  Defender: 'Defensa',
  'Centre-Back': 'Defensa central',
  'Left-Back': 'Lateral izquierdo',
  'Right-Back': 'Lateral derecho',
  Midfield: 'Centrocampista',
  Midfielder: 'Centrocampista',
  'Central Midfield': 'Centrocampista central',
  'Defensive Midfield': 'Centrocampista defensivo',
  'Attacking Midfield': 'Centrocampista ofensivo',
  Offence: 'Delantero',
  Forward: 'Delantero',
  Attack: 'Delantero',
  'Centre-Forward': 'Delantero centro',
  'Left Winger': 'Extremo izquierdo',
  'Right Winger': 'Extremo derecho',
};

export function positionLabel(value) {
  return positionLabels[value] || value || 'No disponible';
}

function dateParts(value) {
  const match = /^(\d{4})-(\d{2})-(\d{2})(?:$|T| )/.exec(value || '');
  if (!match) return null;
  const [, year, month, day] = match.map(Number);
  const date = new Date(year, month - 1, day);
  return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day
    ? { year, month, day, date }
    : null;
}

export function formatDate(value) {
  const parts = dateParts(value);
  return parts ? new Intl.DateTimeFormat('es', { day: 'numeric', month: 'long', year: 'numeric' }).format(parts.date) : 'No disponible';
}

export function formatDateTime(value) {
  if (!value) return 'No disponible';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return 'No disponible';
  return new Intl.DateTimeFormat('es-PE', {
    timeZone: 'America/Lima', dateStyle: 'medium', timeStyle: 'short',
  }).format(date);
}

export function playerAge(value, today = new Date()) {
  const parts = dateParts(value);
  if (!parts || parts.date > today) return null;
  const beforeBirthday = today.getMonth() + 1 < parts.month
    || (today.getMonth() + 1 === parts.month && today.getDate() < parts.day);
  return today.getFullYear() - parts.year - Number(beforeBirthday);
}

export function formatContractMonth(value) {
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(value || '')) return 'No disponible';
  return new Intl.DateTimeFormat('es', { month: 'long', year: 'numeric' }).format(new Date(value + '-01T12:00:00'));
}

export function websiteUrl(value) {
  try {
    const url = new URL(value);
    return ['https:', 'http:'].includes(url.protocol) ? url.href : null;
  } catch {
    return null;
  }
}

function normalizeTeam(team) {
  if (!team) return null;
  return {
    ...team,
    short_name: team.short_name ?? team.shortName ?? null,
    club_colors: team.club_colors ?? team.clubColors ?? null,
    running_competitions: team.running_competitions ?? team.runningCompetitions ?? [],
  };
}

export function playerProfile(payload) {
  const source = payload.player || payload;
  const participations = (payload.participations || []).map(participation => ({
    ...participation,
    team: normalizeTeam(participation.team),
    shirt_number: participation.shirt_number ?? participation.shirtNumber ?? null,
  }));
  const positions = [...new Set(participations.map(item => item.position).filter(Boolean))];
  const shirts = [...new Set(participations.map(item => item.shirt_number).filter(value => value !== null))];
  const currentTeam = normalizeTeam(source.current_team ?? source.currentTeam);
  const individualProfile = Boolean(source.last_updated_at || currentTeam);
  const teams = [...new Map(participations.map(item => [item.team.id, item.team])).values()];
  const name = source.name || [source.first_name ?? source.firstName, source.last_name ?? source.lastName].filter(Boolean).join(' ');
  return {
    player: {
      ...source,
      name,
      first_name: source.first_name ?? source.firstName ?? null,
      last_name: source.last_name ?? source.lastName ?? null,
      date_of_birth: source.date_of_birth ?? source.dateOfBirth ?? null,
      source_updated_at: source.source_updated_at ?? source.lastUpdated ?? null,
      position: source.position || source.section || (!individualProfile && positions.length === 1 ? positions[0] : null),
      shirt_number: source.shirt_number ?? source.shirtNumber ?? (!individualProfile && shirts.length === 1 ? shirts[0] : null),
    },
    currentTeam,
    teams,
    participations,
    multiplePositions: !individualProfile && positions.length > 1 && !source.position && !source.section,
    multipleShirts: !individualProfile && shirts.length > 1 && source.shirt_number == null && source.shirtNumber == null,
    initials: name.trim().split(/\s+/).filter(Boolean).filter((_, index, words) => index === 0 || index === words.length - 1).map(word => [...word][0]).join('').toLocaleUpperCase(),
  };
}
