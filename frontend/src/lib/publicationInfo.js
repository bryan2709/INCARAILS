export function publicationSnapshots(participations = []) {
  const snapshots = new Map();
  for (const participation of participations) {
    if (!participation.snapshot?.id || snapshots.has(participation.snapshot.id)) continue;
    snapshots.set(participation.snapshot.id, {
      ...participation.snapshot,
      competition: participation.competition,
      season: participation.season,
    });
  }
  return [...snapshots.values()];
}
