from ultralytics import YOLO

# โหลดโมเดล YOLOv8n (nano) เพื่อเริ่มต้นเทรน
model = YOLO('yolov8n.pt') 

# --- ส่วนที่ต้องแก้ไข ---
# กำหนด Path สัมพัทธ์จากตำแหน่งของไฟล์ train_yolo.py (ซึ่งอยู่ในโฟลเดอร์ AI)
# เพื่อชี้ไปยังไฟล์ data.yaml
# ใช้ '..' เพื่อกลับไปที่โฟลเดอร์หลัก (your_project_folder) ก่อน แล้วค่อยเข้าโฟลเดอร์ Dataset
data_yaml_path = 'C:/xampp/htdocs/project/yolo_data_trash_v1/data.yaml' 
# ------------------------

# เริ่มการเทรน
# ปรับ epochs (จำนวนรอบ) และ imgsz (ขนาดภาพ) ตามที่ต้องการ
results = model.train(data=data_yaml_path, epochs=5, imgsz=640)

#******************************เปิด Terminal เพื่อรันไฟล์เทรน python AI/train_yolo.py  *********************