'use strict';
function buildAttendancePdf(data, Pdf) {
  const doc = new Pdf({orientation:'landscape', unit:'mm', format:'a4'});
  const rows = data.rows;
  const present = rows.filter(row => row.attendance_status === 'Present').length;
  const absent = rows.filter(row => row.attendance_status === 'Absent').length;
  const fields = ['student_id','student_name','reg_no','year','dept','bus_no','drop_point','attendance_status','staff_user_id'];
  const header = () => {
    doc.setFontSize(16);doc.setTextColor(104,17,114);
    doc.text('GRACE COLLEGE OF ENGINEERING',14,15);
    doc.setFontSize(11);doc.setTextColor(40);
    doc.text('Bus attendance report | ' + data.date,14,23);
    doc.setFontSize(9);
    doc.text('Records: '+rows.length+'   Present: '+present+'   Absent: '+absent,14,30);
    doc.text('Search: '+(data.search || 'All students'),14,36,{maxWidth:265});
  };
  doc.autoTable({
    startY:48, margin:{top:48,bottom:16,left:14,right:14},
    head:[['Student ID','Student name','Register no.','Year','Department','Bus','Drop point','Status','Faculty ID']],
    body:rows.length ? rows.map(row => fields.map(key => String(row[key] ?? 'Unassigned'))) : [['No attendance records for this date and search.','','','','','','','','']],
    styles:{fontSize:8,cellPadding:2.5,overflow:'linebreak'},
    headStyles:{fillColor:[104,17,114],textColor:255},
    alternateRowStyles:{fillColor:[248,243,249]},
    columnStyles:{0:{cellWidth:25},1:{cellWidth:44},2:{cellWidth:29},3:{cellWidth:12},4:{cellWidth:24},5:{cellWidth:18},6:{cellWidth:49},7:{cellWidth:22},8:{cellWidth:46}},
    didDrawPage:header
  });
  const pages=doc.getNumberOfPages();
  for(let page=1;page<=pages;page++) {
    doc.setPage(page);doc.setFontSize(8);doc.setTextColor(100);
    doc.text('Transport details reflect current assignments.',14,202);
    doc.text('Page '+page+' of '+pages,283,202,{align:'right'});
  }
  return doc;
}
if (typeof document !== 'undefined') document.getElementById('download-report-pdf')?.addEventListener('click', async function () {
  const feedback=document.getElementById('pdf-feedback');
  this.disabled=true;feedback.textContent='Preparing PDF report…';
  try {
    const response=await fetch(this.dataset.url,{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Report unavailable. Sign in again and retry.');
    const data=await response.json();
    if(data.error)throw new Error(data.error);
    const doc=buildAttendancePdf(data,window.jspdf.jsPDF);
    doc.save('attendance-'+data.date+'.pdf');feedback.textContent='PDF report downloaded.';
  } catch(error) { feedback.textContent=error.message || 'PDF download failed. Please retry.'; }
  finally { this.disabled=false; }
});
if (typeof module !== 'undefined') module.exports={buildAttendancePdf};
