// admin_script.js
// อธิบาย:
// - checkAuth(): ตรวจ role ใน localStorage (ต้องเป็น admin เท่านั้น)
// - fetchDashboardData(): ดึงข้อมูลจาก API action=dashboard
// - updateKPIs(), renderCharts(), populateTables(): อัปเดต UI
// - setupNavbar(): สลับ section ตามเมนู
// - logout(): เคลียร์และกลับหน้า Login

let chart7d, chartTypes;

document.addEventListener('DOMContentLoaded', () => {
  checkAuth();
  setupNavbar();
  initAdminName();
  fetchDashboardData();
  fetchAllHistoryData();
  

  // ปุ่ม logout อาจอยู่ในเมนู (nav) แทนบน topbar
  const logoutBtn = document.getElementById('logout-btn');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', logout);
  }
});

function timeAgo(dateString) {
  const now = new Date();
  const past = new Date(dateString);
  const diffMs = now - past;
  const diffSec = Math.floor(diffMs / 1000);
  const diffMin = Math.floor(diffSec / 60);
  const diffHr = Math.floor(diffMin / 60);
  const diffDay = Math.floor(diffHr / 24);

  if (diffSec < 60) return 'เมื่อสักครู่';
  if (diffMin < 60) return `${diffMin} นาทีที่แล้ว`;
  if (diffHr < 24) return `${diffHr} ชั่วโมงที่แล้ว`;
  if (diffDay === 1) return 'เมื่อวาน';
  return past.toLocaleDateString('th-TH', {
    year: 'numeric', month: 'long', day: 'numeric',
    hour: '2-digit', minute: '2-digit'
  });
}


function checkAuth() {
  const raw = localStorage.getItem('vs_user');
  if (!raw) { window.location.href = '../login/login2.html'; return; }
  const user = JSON.parse(raw);
  if (user.role !== 'admin') { window.location.href = '../login/login2.html'; }
}

function initAdminName() {
  const user = JSON.parse(localStorage.getItem('vs_user') || '{}');
  const profileIcon = document.querySelector('.profile-icon');
  const nameEl = document.getElementById('admin-name');

  if (user.name) {
    nameEl.textContent = `สวัสดี, ${user.name}`;
    profileIcon && profileIcon.classList.remove('hidden');
  } else {
    nameEl.textContent = 'ผู้ดูแลระบบ';
    profileIcon && profileIcon.classList.add('hidden');
  }
}


async function fetchSystemHealth() {
  try {
    const res = await fetch('../api/get_data.php?action=system_health');
    const data = await res.json();

    updateStatus('status-ai', data.ai);
    updateStatus('status-camera', data.camera);
    updateStatus('status-microbit', data.microbit);
    document.getElementById('uptime').textContent = data.uptime ? timeAgo(data.uptime) : '-';
  } catch (err) {
    console.error('โหลดสถานะระบบไม่สำเร็จ', err);
  }
}
function updateStatus(id, isOnline) {
  const el = document.getElementById(id);
  el.textContent = isOnline ? 'Online' : 'Offline';
  el.classList.toggle('online', isOnline);
  el.classList.toggle('offline', !isOnline);
}
// เรียกตอนโหลดหน้า
fetchSystemHealth();

async function fetchDashboardData() {
  try {
    const res = await fetch('../api/get_data.php?action=dashboard');
    const data = await res.json();
    updateKPIs(data.dashboard);
    renderCharts(data.dashboard);
    populateTables(data);
    requestAnimationFrame(() => {   // ให้ layout ลงตัวก่อน
      if (chart7d) chart7d.resize();
      if (chartTypes) chartTypes.resize();
    });

    // เพิ่มการตรวจสอบข้อมูลก่อนนำไปใช้
    if (res.ok && data.status !== 'error' && data.dashboard) {
      updateKPIs(data.dashboard);
      renderCharts(data.dashboard);
      populateTables(data);
      // ...
    } else {
      console.error('Failed to fetch dashboard data:', data.message || 'ไม่มีข้อมูล dashboard ที่ถูกต้อง');
    }
  } catch (err) {
    console.error('โหลดข้อมูลไม่สำเร็จ', err);
  }
}

async function fetchAllHistoryData() {
  try {
    const res = await fetch('../api/get_data.php?action=all_waste_history');
    const data = await res.json();
    if (data.status === 'success') {
      populateAllHistoryTable(data.history);
    } else {
      console.error('Failed to fetch all history data:', data.message);
    }
  } catch (err) {
    console.error('Error fetching all history data', err);
  }
}

function updateKPIs(kpi) {
  if (kpi) {
    document.getElementById('kpi-total').textContent = Number(kpi.totalCount).toLocaleString();
    document.getElementById('kpi-acc').textContent = `${Number(kpi.aiAccuracy)}%`;
    document.getElementById('kpi-today').textContent = Number(kpi.todayCount).toLocaleString();
  }
}

function renderCharts(dash) {
  // กราฟ 7 วัน
  if (chart7d) chart7d.destroy();
  const ctx1 = document.getElementById('chart7d');
  chart7d = new Chart(ctx1, {
    type: 'bar',
    data: {
      labels: ['6 วันก่อน','5 วันก่อน','4 วันก่อน','3 วันก่อน','2 วันก่อน','วานนี้','วันนี้'],
      // ใช้ข้อมูลจาก API โดยตรง:
      datasets: [{ label: 'จำนวน (ชิ้น)', data: dash.last7Days, backgroundColor: 'rgba(34,197,94,0.6)', borderColor: 'rgba(34,197,94,1)', borderWidth: 1 }]
    },
    // เมื่อสร้าง chart7d และ chartTypes
    options: { 
        responsive: true, 
        maintainAspectRatio: false, // ให้กราฟยืดตาม .chart-wrap ที่กำหนด height
        plugins: { legend: { display: false } },
        animation: { duration: 250 }, // ลด jank ตอน resize 
        scales: { y:{ beginAtZero:true, ticks:{ precision:0 } } } }
  });

  // กราฟสัดส่วนประเภทขยะ (ชื่อ key ตามฐานข้อมูลตัวอย่าง)
  if (chartTypes) chartTypes.destroy();
  const ctx2 = document.getElementById('chartTypes');

  // สร้าง labels/values อัตโนมัติจาก object ชนิดขยะ
  const typeLabels = Object.keys(dash.types);
  const typeValues = Object.values(dash.types);

  chartTypes = new Chart(ctx2, {
    type: 'doughnut',
    data: {
      // ใช้ข้อมูลจาก API โดยตรง:
      labels: Object.keys(dash.types),
      datasets: [{ data: Object.values(dash.types),
        backgroundColor: ['#22c55e','#3b82f6','#f59e0b','#6b7280','#ef4444'] }]
    },
    options: { responsive: true, maintainAspectRatio: false }
  });
}

function statusBadge(status) {
  if (status === 'full') {
    return `<span class="status full">เต็ม</span>`;
  } else {
    return `<span class="status not-full">ยังไม่เต็ม</span>`;
  }
}

function formatThaiDateTime(dateString) {
  const months = [
    'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
    'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'
  ];
  const dateObj = new Date(dateString);
  const day = dateObj.getDate();
  const month = months[dateObj.getMonth()];
  const year = dateObj.getFullYear() + 543; // แปลงเป็น พ.ศ.
  const time = dateObj.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  return `${day} ${month} ${year} ${time}`;
}

function populateAllHistoryTable(historyData) {
  const hBody = document.getElementById('all-history-tbody');
  hBody.innerHTML = '';
  if (historyData.length > 0) {
    historyData.forEach(h => {
      const tr = document.createElement('tr');
      const dateTime = h.timestamp ? new Date(h.timestamp) : null;
      const date = dateTime ? dateTime.toLocaleDateString('th-TH') : '-';
      const time = dateTime ? dateTime.toLocaleTimeString('th-TH') : '-';

      tr.innerHTML = `
        <td>${h.bin_id ?? '-'}</td>
        <td>${h.user_id ?? '-'} (${h.user_name ?? '-'})</td>
        <td>${date}</td>
        <td>${time}</td>
        <td>${h.waste_type ?? '-'}</td>
        <td>${h.score ?? 0}</td>
      `;
      hBody.appendChild(tr);
    });
  } else {
    hBody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:var(--muted)">ไม่มีข้อมูลการทิ้งขยะ</td></tr>`;
  }
}

function populateTables(data) {
  // Users
  const uBody = document.getElementById('users-tbody');
  uBody.innerHTML = '';
  (data.users || []).forEach(u => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
    <td>${u.id}</td>
    <td>${u.name}</td>
    <td>${u.role}</td>
    <td>${u.class ?? '-'}</td>
    <td>${u.score ?? 0}</td>`;
    uBody.appendChild(tr);
  });

  // Bins
  const bBody = document.getElementById('bins-tbody');
  bBody.innerHTML = '';
  (data.bins || []).forEach(b => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${b.id}</td>
      <td>${b.location}</td>
      <td>${statusBadge(b.plastic_status)}</td>
      <td>${statusBadge(b.recycle_status)}</td>
      <td>${statusBadge(b.general_status)}</td>
      <td>${formatThaiDateTime(b.last_updated)}</td>`;
    bBody.appendChild(tr);
  });

  // Feedback
  const fBody = document.getElementById('feedback-tbody');
  fBody.innerHTML = '';
  (data.feedback || []).forEach(f => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
    <td>${f.user_id ?? '-'}</td>
    <td>${f.bin_id ?? '-'}</td>
    <td>${escapeHTML(f.message)}</td>
    <td>${formatThaiDateTime(f.timestamp)}</td>`;
    fBody.appendChild(tr);
  });
}


function setupNavbar() {
  const nav = document.querySelector('.nav');
  const toggle = document.querySelector('.menu-toggle');

  if (toggle && nav) {
    toggle.addEventListener('click', () => nav.classList.toggle('show'));
  }

  document.querySelectorAll('.nav-item').forEach(a => {
    a.addEventListener('click', (e) => {
      e.preventDefault();

      document.querySelectorAll('.nav-item').forEach(nav => nav.classList.remove('active'));
      a.classList.add('active');

      document.querySelectorAll('.dashboard-section').forEach(sec => sec.classList.remove('active'));
      const targetId = a.dataset.target;
      const section = document.getElementById(targetId);
      section.classList.add('active');

      nav.classList.remove('show');

      requestAnimationFrame(() => {
        if (targetId === 'overview') {
          if (chart7d) chart7d.resize();
          if (chartTypes) chartTypes.resize();
        }

        // ✅ ย้ายการเรียกฟังก์ชัน fetchAllHistoryData() มาที่นี่
        if (targetId === 'bins') {
          fetchAllHistoryData();
          if (window.binMap) {
            setTimeout(() => {
              window.binMap.map.invalidateSize();
              if (window.binMap.markers.length > 0) {
                window.binMap.map.fitBounds(window.binMap.markers);
              }
            }, 200);
          }
        }
      });
    });
  });
}


function logout() {
  localStorage.removeItem('vs_user');
  window.location.href = '../login/login2.html';
}

// ป้องกัน XSS เวลาแสดงข้อความฟีดแบ็ก
function escapeHTML(str) {
  return str.replace(/[&<>'"]/g, tag => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    "'": '&#39;',
    '"': '&quot;'
  }[tag]));
}
