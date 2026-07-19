/**
 * Lapok DMS &mdash; Manager fleet map (drivers, cadets, routes)
 */
let fleetMapInstance = null;
let fleetMapLayers = { markers: [], routes: [], stops: [] };
let fleetMapRefreshTimer = null;
let fleetMapData = null;

const FLEET_ROUTE_COLORS = ['#E53E3E', '#2563EB', '#7C3AED', '#D97706', '#059669', '#DB2777'];

function fleetMapCanView() {
  return currentUser && ['admin', 'manager'].includes(currentUser.role);
}

async function loadFleetMapPage() {
  if (!fleetMapCanView()) return;
  const mapEl = document.getElementById('fleetMapCanvas');
  if (!mapEl) return;

  if (typeof L === 'undefined') {
    document.getElementById('fleetMapSidebar').innerHTML =
      '<p style="color:var(--red);padding:1rem">Map library failed to load. Check your internet connection.</p>';
    return;
  }

  if (!fleetMapInstance) {
    fleetMapInstance = L.map('fleetMapCanvas', { scrollWheelZoom: true }).setView([2.7726, 32.2988], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap',
      maxZoom: 19,
    }).addTo(fleetMapInstance);
  }

  await refreshFleetMap();
  if (fleetMapRefreshTimer) clearInterval(fleetMapRefreshTimer);
  fleetMapRefreshTimer = setInterval(refreshFleetMap, 30000);
}

function clearFleetMapLayers() {
  fleetMapLayers.markers.forEach((m) => fleetMapInstance.removeLayer(m));
  fleetMapLayers.routes.forEach((r) => fleetMapInstance.removeLayer(r));
  fleetMapLayers.stops.forEach((s) => fleetMapInstance.removeLayer(s));
  fleetMapLayers = { markers: [], routes: [], stops: [] };
}

function fleetVehicleIcon(type, color) {
  const emoji = type === 'tuktuk' ? '🛺' : '🚛';
  return L.divIcon({
    className: 'fleet-map-marker',
    html: `<div class="fleet-pin" style="border-color:${color}">${emoji}</div>`,
    iconSize: [36, 36],
    iconAnchor: [18, 18],
  });
}

function fleetStopIcon(order) {
  return L.divIcon({
    className: 'fleet-stop-marker',
    html: `<div class="fleet-stop">${order}</div>`,
    iconSize: [22, 22],
    iconAnchor: [11, 11],
  });
}

function fleetSourceLabel(src) {
  return {
    gps: 'Live GPS',
    estimated: 'Estimated (stop progress)',
    route_centroid: 'Route area',
    depot: 'Depot',
    manual: 'Manual',
    unavailable: 'Awaiting GPS',
  }[src] || src;
}

function renderFleetSidebar(fleet) {
  const el = document.getElementById('fleetMapSidebar');
  if (!el) return;
  if (!fleet.length) {
    el.innerHTML = '<p style="color:var(--gray-mid);padding:1rem">No vehicles on route right now.</p>';
    return;
  }
  el.innerHTML = fleet.map((v, i) => {
    const color = FLEET_ROUTE_COLORS[i % FLEET_ROUTE_COLORS.length];
    const crew = [
      v.driver ? `Driver: ${escFleet(v.driver.name)}` : '',
      v.cadet ? `Cadet: ${escFleet(v.cadet.name)}` : '',
    ].filter(Boolean).join(' &middot; ') || 'Unassigned crew';
    const route = v.route?.name || v.registration;
    const stops = v.route?.stops?.length || 0;
    const ping = v.last_ping?.recorded_at
      ? LapokAPI.formatTime(v.last_ping.recorded_at)
      : '&mdash;';
    return `<div class="fleet-card" data-vehicle-id="${v.vehicle_id}" onclick="fleetFocusVehicle(${v.vehicle_id})">
      <div class="fleet-card-top">
        <span class="fleet-card-plate" style="border-left:4px solid ${color}">${escFleet(v.registration)}</span>
        <span class="badge ${v.trip ? 'bs' : 'bg'}">${v.trip ? 'On route' : 'Idle'}</span>
      </div>
      <div class="fleet-card-meta">${crew}</div>
      <div class="fleet-card-meta"><strong>Route:</strong> ${escFleet(route)} &middot; ${stops} stops</div>
      <div class="fleet-card-meta" style="font-size:11px;color:var(--gray-mid)">${fleetSourceLabel(v.position_source)} &middot; ${ping}</div>
    </div>`;
  }).join('');
}

function fleetFocusVehicle(vehicleId) {
  const v = (fleetMapData?.fleet || []).find((x) => x.vehicle_id === vehicleId);
  if (!v?.position || !fleetMapInstance) return;
  fleetMapInstance.setView([v.position.lat, v.position.lng], 15, { animate: true });
}

async function refreshFleetMap() {
  if (!fleetMapCanView() || !fleetMapInstance) return;
  const statusEl = document.getElementById('fleetMapStatus');
  try {
    fleetMapData = await LapokAPI.get('/api/fleet/map_tracking.php');
    if (statusEl) {
      statusEl.textContent = 'Updated ' + LapokAPI.formatTime(fleetMapData.refreshed_at);
    }
    clearFleetMapLayers();
    const bounds = [];
    const depot = fleetMapData.depot;
    if (depot) {
      bounds.push([depot.lat, depot.lng]);
      const depotMarker = L.marker([depot.lat, depot.lng], {
        icon: L.divIcon({
          className: 'fleet-depot-marker',
          html: '<div class="fleet-depot">🏭</div>',
          iconSize: [30, 30],
          iconAnchor: [15, 15],
        }),
      }).bindPopup('<strong>Depot</strong>');
      depotMarker.addTo(fleetMapInstance);
      fleetMapLayers.markers.push(depotMarker);
    }

    (fleetMapData.fleet || []).forEach((v, i) => {
      const color = FLEET_ROUTE_COLORS[i % FLEET_ROUTE_COLORS.length];
      if (v.route?.path?.length > 1) {
        const line = L.polyline(v.route.path, { color, weight: 4, opacity: 0.55, dashArray: '6 8' });
        line.addTo(fleetMapInstance);
        fleetMapLayers.routes.push(line);
        v.route.path.forEach((pt) => bounds.push(pt));
      }
      (v.route?.stops || []).filter((st) => st.lat != null && st.lng != null).forEach((st) => {
        const sm = L.marker([st.lat, st.lng], { icon: fleetStopIcon(st.stop_order) })
          .bindPopup(`<strong>Stop ${st.stop_order}</strong><br>${escFleet(st.name)}<br><small>${escFleet(st.location || '')}</small>`);
        sm.addTo(fleetMapInstance);
        fleetMapLayers.stops.push(sm);
        bounds.push([st.lat, st.lng]);
      });
      if (v.position) {
        const crew = [
          v.driver ? `Driver: ${v.driver.name}` : null,
          v.cadet ? `Cadet: ${v.cadet.name}` : null,
        ].filter(Boolean).join('<br>') || 'No crew assigned';
        const popup = `<strong>${escFleet(v.registration)}</strong> (${v.vehicle_type})<br>
          ${crew}<br>
          <strong>Route:</strong> ${escFleet(v.route?.name || '&mdash;')}<br>
          <small>${fleetSourceLabel(v.position_source)}</small>`;
        const marker = L.marker([v.position.lat, v.position.lng], {
          icon: fleetVehicleIcon(v.vehicle_type, color),
        }).bindPopup(popup);
        marker.addTo(fleetMapInstance);
        fleetMapLayers.markers.push(marker);
        bounds.push([v.position.lat, v.position.lng]);
      }
    });

    renderFleetSidebar(fleetMapData.fleet || []);
    if (bounds.length > 1) {
      fleetMapInstance.fitBounds(bounds, { padding: [40, 40], maxZoom: 14 });
    }
    setTimeout(() => fleetMapInstance.invalidateSize(), 200);
  } catch (e) {
    if (statusEl) statusEl.textContent = 'Error loading map';
    console.warn('Fleet map:', e.message);
  }
}

function escFleet(s) {
  return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function stopFleetMapRefresh() {
  if (fleetMapRefreshTimer) {
    clearInterval(fleetMapRefreshTimer);
    fleetMapRefreshTimer = null;
  }
}

/** Field users &mdash; send GPS ping when on an active trip (browser geolocation). */
let fleetLocationPingTimer = null;

function startFieldLocationPing() {
  if (!currentUser || !['cadet', 'driver', 'field_user'].includes(currentUser.role)) return;
  if (!navigator.geolocation) return;
  if (fleetLocationPingTimer) return;

  const send = () => {
    navigator.geolocation.getCurrentPosition(
      async (pos) => {
        try {
          await LapokAPI.post('/api/fleet/location_ping.php', {
            latitude: pos.coords.latitude,
            longitude: pos.coords.longitude,
            accuracy_m: Math.round(pos.coords.accuracy || 0),
            speed_kmh: pos.coords.speed != null ? +(pos.coords.speed * 3.6).toFixed(1) : null,
            heading: pos.coords.heading != null ? Math.round(pos.coords.heading) : null,
          });
        } catch (_) {}
      },
      () => {},
      { enableHighAccuracy: true, maximumAge: 60000, timeout: 12000 }
    );
  };

  send();
  fleetLocationPingTimer = setInterval(send, 120000);
}
