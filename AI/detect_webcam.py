import cv2
from ultralytics import YOLO

# รอโมเดลเทรนเสร็จแล้วค่อยใส่ path
# เช่น model = YOLO('path/to/your/best.pt')
# คุณจะได้ไฟล์ .pt อยู่ในโฟลเดอร์ runs/
model = YOLO('AI/best2.pt')

# เปิดใช้งานกล้องเว็บแคม7
cap = cv2.VideoCapture(0)

# ลูปเพื่ออ่านภาพจากกล้อง
while cap.isOpened():
    ret, frame = cap.read()
    if not ret:
        break

    # ใช้โมเดล YOLO เพื่อตรวจจับวัตถุในเฟรม
    results = model(frame)

    # แสดงผลลัพธ์บนเฟรม
    annotated_frame = results[0].plot()

    # แสดงผลลัพธ์
    cv2.imshow("YOLOv8 Live Detection", annotated_frame)

    # กด 'q' เพื่อออก
    if cv2.waitKey(1) & 0xFF == ord("q"):
        break

# ปิดกล้องและหน้าต่างแสดงผล
cap.release()
cv2.destroyAllWindows()

#****************************เปิด Terminal ก่อนแล้วรัน python AI/detect_webcam.py ******************ๆ